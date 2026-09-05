<?php

namespace App\Support\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Redis;
use Throwable;

/**
 * A phpredis connection that survives Sentinel failovers inside the failing command.
 *
 * Laravel's own PhpRedisConnection::command() rebuilds the client on failover-class errors from the address it
 * already knows and retries only read-only commands, once; writes are re-thrown and nothing rediscovers the
 * master - one visible failure per live connection per failover.
 * This subclass instead retries in a bounded loop owned by {@see SentinelRetryPolicy}: log, rebuild the client
 * through the connector closure with `refresh: true` (forced fresh sentinel discovery, see
 * PhpRedisSentinelConnector), wait, and re-run. When the promotion lands inside the budget, web requests and
 * Horizon long-runners heal with zero visible failures.
 *
 * Only entry points that talk to the client DIRECTLY are wrapped: command(), the scan family, pipeline(),
 * transaction() and (p)subscribe(). Everything else - __call, eval, evalsha, flushdb - already funnels through
 * $this->command(), and wrapping those too would nest the loops and square the retry budget.
 *
 * Known caveat, inherent to every sentinel client: a retried write whose response was lost may execute twice
 * on the server, and a retried pipeline replays every write in the batch. The budget should ride out a
 * failover, not an outage.
 */
class PhpRedisSentinelConnection extends PhpRedisConnection
{
    /**
     * Whether the client in place is known-dead and must be rebuilt before the next operation.
     *
     * Set when a budget is exhausted: this connection stays in the manager's pool for the rest of the process,
     * and handing the next caller a corpse would cost them a wasted attempt to discover what we already know.
     */
    protected bool $clientIsStale = false;

    /**
     * Create the connection.
     *
     * @param  Redis  $client  The connected phpredis client.
     * @param  callable(bool=): Redis  $connector  Client factory; `true` forces fresh sentinel discovery.
     * @param  array  $config  The connection configuration, discovery keys already stripped.
     * @param  SentinelRetryPolicy  $retryPolicy  The failover budget shared with the connector.
     */
    public function __construct(
        $client,
        ?callable $connector,
        array $config,
        protected SentinelRetryPolicy $retryPolicy = new SentinelRetryPolicy,
    ) {
        parent::__construct($client, $connector, $config);
    }

    /**
     * {@inheritdoc}
     *
     * Calls the grandparent rather than {@see PhpRedisConnection::command()} on purpose. The vendor loop
     * rebuilds the client from the *cached* address and re-runs read-only commands there (once, or
     * `command_retries` times), which on this driver means a second - and, when that address is dead, a
     * third - connect inside every retry, all of it charged to our deadline and none of it rediscovering the
     * master. Its healing is not lost: RETRYABLE_ERROR_FRAGMENTS covers every fragment the vendor heals on,
     * and the budget applies to writes too.
     */
    public function command($method, array $parameters = [])
    {
        return $this->retryOnFailure(fn() => Connection::command($method, $parameters));
    }

    /**
     * {@inheritdoc}
     */
    public function scan($cursor, $options = [])
    {
        return $this->retryOnFailure(fn() => parent::scan($cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function zscan($key, $cursor, $options = [])
    {
        return $this->retryOnFailure(fn() => parent::zscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function hscan($key, $cursor, $options = [])
    {
        return $this->retryOnFailure(fn() => parent::hscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function sscan($key, $cursor, $options = [])
    {
        return $this->retryOnFailure(fn() => parent::sscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * The whole pipeline re-runs on retry - the SessionRegistry liveness check and any other
     * pipelined reads heal like single commands do.
     */
    public function pipeline(?callable $callback = null)
    {
        return $this->retryOnFailure(fn() => parent::pipeline($callback));
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(?callable $callback = null)
    {
        return $this->retryOnFailure(fn() => parent::transaction($callback));
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe($channels, Closure $callback)
    {
        $this->retryOnFailure(
            function () use ($channels, $callback): void {
                $this->withoutReadTimeout(fn() => parent::subscribe($channels, $callback));
            },
            $this->retryPolicy->forBlockingOperations(),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function psubscribe($channels, Closure $callback)
    {
        $this->retryOnFailure(
            function () use ($channels, $callback): void {
                $this->withoutReadTimeout(fn() => parent::psubscribe($channels, $callback));
            },
            $this->retryPolicy->forBlockingOperations(),
        );
    }

    /**
     * Run the operation, rediscovering the master and retrying on failover-class errors.
     *
     * @param  callable  $callback  The client operation.
     * @param  SentinelRetryPolicy|null  $policy  Overrides the connection's budget; blocking reads need
     *   different semantics from commands, see SentinelRetryPolicy::forBlockingOperations().
     * @return mixed The first successful result.
     *
     * @throws SentinelFailoverException When the retry budget is exhausted (original error as previous).
     * @throws \RedisException When the error is not failover-class (propagated untouched).
     */
    protected function retryOnFailure(callable $callback, ?SentinelRetryPolicy $policy = null)
    {
        try {
            return ($policy ?? $this->retryPolicy)->run(
                function () use ($callback) {
                    $this->refreshStaleClient();

                    return $callback();
                },
                $this->refreshClient(...),
                sprintf('connection [%s]', $this->getName() ?? 'unknown'),
            );
        } catch (SentinelFailoverException $exception) {
            /*
             * Flag rather than rebuild: a rebuild costs a discovery sweep plus a connect, which is precisely
             * the spend the deadline just refused. The next operation pays for it, and only if there is one.
             */
            $this->clientIsStale = true;

            throw $exception;
        }
    }

    /**
     * Rebuild a client that a previous exhausted budget left for dead.
     *
     * Runs inside the retry loop, so a rediscovery that fails here degrades into an ordinary retryable
     * iteration instead of escaping. The flag is cleared first: one forced rebuild per exhaustion is the
     * point, the loop owns everything after that.
     */
    private function refreshStaleClient(): void
    {
        if (!$this->clientIsStale) {
            return;
        }

        $this->clientIsStale = false;

        $this->refreshClient();
    }

    /**
     * Replace the client with one pointing at the freshly discovered master.
     *
     * The replacement is built before the old one is let go, so a rediscovery that fails mid-flap leaves a
     * connection that may still serve reads rather than a closed socket. Nothing is closed explicitly:
     * dropping the last reference closes an ordinary socket and returns a persistent one to phpredis' pool,
     * which is right for both and cannot yank a socket a pooled sibling is still sharing.
     */
    protected function refreshClient(): void
    {
        if ($this->connector === null) {
            return;
        }

        try {
            $this->client = ($this->connector)(true);
        } catch (Throwable $exception) {
            Log::warning(sprintf(
                'Redis sentinel connection [%s]: master rediscovery failed, keeping the previous client for the next attempt: %s',
                $this->getName() ?? 'unknown',
                $exception->getMessage(),
            ));
        }
    }

    /**
     * Run a blocking operation with the socket read timeout lifted.
     *
     * Sentinel connections carry a bounded `read_timeout` because a dying master can accept the handshake and
     * then never answer, and an unbounded read there hangs the request instead of entering the retry path. A
     * subscriber, though, legitimately sits idle for minutes: with the bound in place phpredis raises a read
     * error every `read_timeout` seconds, and because that error IS failover-class the loop would rediscover
     * its way through the whole budget and then kill a perfectly healthy subscription. Blocking reads
     * therefore run unbounded, restored afterwards so ordinary command semantics come back.
     *
     * Lifting the socket bound is only half of it - the retry budget has to stop being scoped to the whole
     * subscription too, or a subscriber outlives its own deadline and gets no retries at all. See
     * {@see SentinelRetryPolicy::forBlockingOperations()}.
     */
    private function withoutReadTimeout(Closure $callback): mixed
    {
        $previous = $this->client->getOption(Redis::OPT_READ_TIMEOUT);

        $this->client->setOption(Redis::OPT_READ_TIMEOUT, -1);

        try {
            return $callback();
        } finally {
            $this->client->setOption(Redis::OPT_READ_TIMEOUT, $previous);
        }
    }
}
