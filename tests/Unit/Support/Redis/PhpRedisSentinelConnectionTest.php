<?php

namespace Tests\Unit\Support\Redis;

use App\Support\Redis\PhpRedisSentinelConnection;
use App\Support\Redis\SentinelFailoverException;
use App\Support\Redis\SentinelRetryPolicy;
use RedisException;
use RuntimeException;
use Tests\TestCase;

/**
 * The failover retry loop as pure logic: fake clients and connector closures, no sockets.
 * What matters here is the contract the failover drill relies on - retryable errors trigger
 * refresh-flagged rediscovery and an in-command retry, everything else propagates untouched.
 */
class PhpRedisSentinelConnectionTest extends TestCase
{
    /**
     * A connection whose client is scripted and whose connector records refresh flags.
     *
     * @param  object  $client  The initial (possibly failing) fake client.
     * @param  object|null  $replacement  What the connector hands back on rebuild.
     * @param  list<bool>  $refreshes  Filled with the refresh flag of every connector call.
     */
    private function connection(
        object $client,
        ?object $replacement,
        array &$refreshes,
        int $attempts = 3,
        int $deadlineMs = 0,
        int $delayMs = 0,
    ): object {
        $connector = function (bool $refresh = false) use (&$refreshes, $replacement, $client): object {
            $refreshes[] = $refresh;

            return $replacement ?? $client;
        };

        return new class($client, $connector, [], new SentinelRetryPolicy($attempts, $delayMs, $deadlineMs)) extends PhpRedisSentinelConnection
        {
            public function exposeRetry(callable $callback): mixed
            {
                return $this->retryOnFailure($callback);
            }

            /** So a callback can talk to whatever client the connection currently holds, not a captured one. */
            public function currentClient(): object
            {
                return $this->client;
            }
        };
    }

    /**
     * A client that fails a given number of times before succeeding.
     */
    private function flakyClient(int $failures, string $message): object
    {
        return new class($failures, $message)
        {
            public function __construct(private int $failures, private readonly string $message)
            {
            }

            public function ping(): string
            {
                if ($this->failures-- > 0) {
                    throw new RedisException($this->message);
                }

                return 'PONG';
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * A client that logs every read-timeout change and fails the first $failures subscriptions.
     */
    private function subscriberClient(int $failures): object
    {
        return new class($failures)
        {
            /** @var list<float> Every value OPT_READ_TIMEOUT was set to, in order. */
            public array $readTimeouts = [];

            public int $subscribes = 0;

            private float $readTimeout = 2.0;

            public function __construct(private int $failures) {}

            public function getOption(int $option): float
            {
                return $option === \Redis::OPT_READ_TIMEOUT ? $this->readTimeout : 0.0;
            }

            public function setOption(int $option, mixed $value): bool
            {
                if ($option === \Redis::OPT_READ_TIMEOUT) {
                    $this->readTimeout = (float) $value;
                    $this->readTimeouts[] = (float) $value;
                }

                return true;
            }

            public function subscribe(array $channels, callable $callback): void
            {
                $this->subscribes++;

                if ($this->failures-- > 0) {
                    throw new RedisException('Connection lost');
                }
            }

            public function psubscribe(array $patterns, callable $callback): void
            {
                $this->subscribe($patterns, $callback);
            }
        };
    }

    public function test_subscriptions_get_blocking_retry_semantics(): void
    {
        /*
         * A 1ms deadline against a 10ms inter-attempt delay, so the very first failure is already over budget:
         * an ordinary command gets zero retries here (see the sibling test), because for a command the elapsed
         * time is effort spent recovering. For a subscriber it is the subscription's own healthy lifetime, so
         * the deadline must not apply - otherwise every subscription older than it dies on its first failover.
         */
        $client = $this->subscriberClient(failures: 2);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 1, delayMs: 10);

        $connection->subscribe(['events'], static fn() => null);

        $this->assertSame(3, $client->subscribes, 'the deadline must not be charged against a subscription');
        $this->assertSame([true, true], $refreshes);
    }

    public function test_a_command_on_the_same_connection_still_honours_the_deadline(): void
    {
        // The blocking policy is per-call, so it must not leak into ordinary traffic on the connection.
        $client = $this->flakyClient(PHP_INT_MAX, 'Connection lost');
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 1, delayMs: 10);

        try {
            $connection->exposeRetry(fn() => $client->ping());
            $this->fail('Expected the deadline to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertStringContainsString('gave up after 0 retries', $exception->getMessage());
        }
    }

    public function test_a_subscription_runs_with_the_read_timeout_lifted_and_puts_it_back(): void
    {
        /*
         * Sentinel connections carry a bounded read_timeout so a dying master cannot hang a request, but an
         * idle subscriber would then raise a read error every couple of seconds - a failover-class one, so the
         * loop would rediscover its way through the budget and kill a perfectly healthy subscription.
         */
        $client = $this->subscriberClient(failures: 0);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes);

        $connection->subscribe(['events'], static fn() => null);

        $this->assertSame([-1.0, 2.0], $client->readTimeouts, 'lifted for the subscription, restored after it');
    }

    public function test_the_read_timeout_is_restored_even_when_the_subscription_dies(): void
    {
        // Restored in a finally: a connection left with an unbounded read timeout would hang the next
        // ordinary command against a dying master, which is the whole thing the bound exists to prevent.
        $client = $this->subscriberClient(failures: PHP_INT_MAX);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 1);

        try {
            $connection->subscribe(['events'], static fn() => null);
            $this->fail('Expected the subscription to give up.');
        } catch (SentinelFailoverException) {
            $this->assertSame([-1.0, 2.0, -1.0, 2.0], $client->readTimeouts);
        }
    }

    public function test_psubscribe_behaves_like_subscribe(): void
    {
        $client = $this->subscriberClient(failures: 1);
        $refreshes = [];
        $connection = $this->connection($client, null, $refreshes, attempts: 2, deadlineMs: 1, delayMs: 10);

        $connection->psubscribe(['events.*'], static fn() => null);

        $this->assertSame(2, $client->subscribes);
        $this->assertSame([-1.0, 2.0, -1.0, 2.0], $client->readTimeouts);
    }

    public function test_retries_after_a_retryable_failure_with_forced_rediscovery(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(1, 'READONLY You can\'t write against a read only replica.');
        $connection = $this->connection($client, null, $refreshes);

        $result = $connection->exposeRetry(fn() => $client->ping());

        $this->assertSame('PONG', $result);
        $this->assertSame([true], $refreshes, 'the rebuild must force fresh sentinel discovery');
    }

    public function test_the_retry_predicate_is_case_insensitive(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(1, 'CONNECTION REFUSED by peer');
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame('PONG', $connection->exposeRetry(fn() => $client->ping()));
    }

    public function test_discovery_failures_mid_command_spend_retry_budget_instead_of_escaping(): void
    {
        // A discovery failure raised while sentinels ARE answering means an election is in flight,
        // so the loop must spend budget on it rather than let it escape.
        $refreshes = [];
        $calls = 0;

        $flaky = function () use (&$calls): string {
            if (++$calls === 1) {
                throw new \App\Support\Redis\SentinelDiscoveryException(
                    'Unable to resolve the Redis master for service [mymaster]',
                    anySentinelAnswered: true,
                );
            }

            return 'PONG';
        };

        $client = $this->flakyClient(0, 'unused');
        $connection = $this->connection($client, null, $refreshes);

        $this->assertSame('PONG', $connection->exposeRetry($flaky));
        $this->assertSame([true], $refreshes, 'a discovery failure must force refreshed rediscovery');
    }

    public function test_non_retryable_errors_propagate_immediately_without_rediscovery(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(1, 'WRONGTYPE Operation against a key holding the wrong kind of value');
        $connection = $this->connection($client, null, $refreshes);

        try {
            $connection->exposeRetry(fn() => $client->ping());
            $this->fail('Expected the application error to propagate untouched.');
        } catch (RedisException $exception) {
            $this->assertStringContainsString('WRONGTYPE', $exception->getMessage());
            $this->assertSame([], $refreshes, 'application errors must never trigger rediscovery');
        }
    }

    public function test_exhaustion_wraps_the_original_error_after_the_configured_retries(): void
    {
        $refreshes = [];
        $client = $this->flakyClient(PHP_INT_MAX, 'Connection lost');
        $connection = $this->connection($client, null, $refreshes, attempts: 2);

        try {
            $connection->exposeRetry(fn() => $client->ping());
            $this->fail('Expected the retry budget to exhaust.');
        } catch (SentinelFailoverException $exception) {
            // A RedisException subclass, so `catch (RedisException)` around Redis work still holds.
            $this->assertInstanceOf(RedisException::class, $exception);
            $this->assertStringContainsString('gave up after 2 retries', $exception->getMessage());
            $this->assertInstanceOf(RedisException::class, $exception->getPrevious());
            $this->assertCount(2, $refreshes);
        }
    }

    public function test_pipelines_retry_like_single_commands(): void
    {
        $healthy = new class
        {
            public function pipeline(): object
            {
                return new class
                {
                    public function exec(): array
                    {
                        return [1, 1];
                    }
                };
            }

            public function close(): void
            {
            }
        };

        $failing = new class
        {
            public function pipeline(): never
            {
                throw new RedisException('Redis server went away');
            }

            public function close(): void
            {
            }
        };

        $refreshes = [];
        $connection = $this->connection($failing, $healthy, $refreshes);

        // The vendor pipeline() talks to the client directly, bypassing command() - the override
        // must give it the same healing the SessionRegistry liveness check depends on.
        $result = $connection->pipeline(function (): void {
        });

        $this->assertSame([1, 1], $result);
        $this->assertSame([true], $refreshes);
    }

    public function test_an_exhausted_budget_leaves_the_connection_usable_for_the_next_command(): void
    {
        /*
         * The connection stays in the manager's pool for the rest of the process, so an exhausted budget must
         * not hand the next caller a corpse. It is flagged rather than rebuilt on the way out - rebuilding
         * costs a discovery sweep plus a connect, which is exactly the spend the deadline just refused.
         */
        $dead = $this->flakyClient(PHP_INT_MAX, 'Connection refused');
        $healthy = $this->flakyClient(0, 'unused');
        $handOut = $dead;
        $refreshes = [];

        $connector = function (bool $refresh = false) use (&$handOut, &$refreshes): object {
            $refreshes[] = $refresh;

            return $handOut;
        };

        $connection = new class($dead, $connector, [], new SentinelRetryPolicy(1, 0, 0)) extends PhpRedisSentinelConnection
        {
            public function exposeRetry(callable $callback): mixed
            {
                return $this->retryOnFailure($callback);
            }

            public function currentClient(): object
            {
                return $this->client;
            }
        };

        try {
            $connection->exposeRetry(fn() => $connection->currentClient()->ping());
            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException) {
            // Expected: nothing is reachable yet.
        }

        // The master comes back. The next command must rebuild before running anything, and must decide to do
        // so from the flag - not by parsing whatever the abandoned socket happens to say when poked.
        $handOut = $healthy;
        $refreshes = [];

        $this->assertSame('PONG', $connection->exposeRetry(fn() => $connection->currentClient()->ping()));
        $this->assertSame([true], $refreshes, 'the stale client must be rebuilt before the operation runs');
    }

    public function test_a_failed_rediscovery_keeps_the_previous_client_instead_of_a_closed_one(): void
    {
        /*
         * refreshClient() used to close the old client before building its replacement, so a rediscovery that
         * failed mid-flap left a closed socket in place while logging that it had kept the previous one.
         */
        $client = $this->flakyClient(1, 'Connection lost');
        $rebuilds = 0;

        $connector = function (bool $refresh = false) use (&$rebuilds): object {
            $rebuilds++;

            throw new \App\Support\Redis\SentinelDiscoveryException('no master yet', anySentinelAnswered: true);
        };

        $connection = new class($client, $connector, [], new SentinelRetryPolicy(3, 0, 0)) extends PhpRedisSentinelConnection
        {
            public function exposeRetry(callable $callback): mixed
            {
                return $this->retryOnFailure($callback);
            }

            public function currentClient(): object
            {
                return $this->client;
            }
        };

        $this->assertSame('PONG', $connection->exposeRetry(fn() => $connection->currentClient()->ping()));
        $this->assertSame(1, $rebuilds);
        $this->assertSame($client, $connection->currentClient(), 'the original client must survive a failed rebuild');
    }

    public function test_a_failed_rediscovery_is_survivable_within_the_budget(): void
    {
        $callCount = 0;
        $client = $this->flakyClient(2, 'Connection lost');

        $connector = function (bool $refresh = false) use (&$callCount, $client): object {
            if (++$callCount === 1) {
                // Mid-failover: sentinels reachable but no master elected yet.
                throw new RuntimeException('Unable to resolve the Redis master');
            }

            return $client;
        };

        $connection = new class($client, $connector, [], new SentinelRetryPolicy(3, 0, 0)) extends PhpRedisSentinelConnection
        {
            public function exposeRetry(callable $callback): mixed
            {
                return $this->retryOnFailure($callback);
            }
        };

        $this->assertSame('PONG', $connection->exposeRetry(fn() => $client->ping()));
        $this->assertSame(2, $callCount, 'the second attempt must rediscover again after the first failed');
    }
}
