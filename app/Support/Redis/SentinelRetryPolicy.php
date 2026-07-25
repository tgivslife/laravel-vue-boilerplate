<?php

namespace App\Support\Redis;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RedisException;
use Throwable;

/**
 * The failover retry budget, and the single definition of what "the master moved" looks like.
 *
 * Both halves of a failover share it, so both behave the same way:
 * {@see PhpRedisSentinelConnector} wraps the initial client creation and {@see PhpRedisSentinelConnection}
 * wraps every command on an already-open connection. Connect-time coverage is not an afterthought - php-fpm
 * discards class statics between requests and rebuilds all four connections from scratch, so a request that
 * arrives during an election never reaches the in-command loop at all.
 *
 * The budget is bounded twice over, and the second bound is the load-bearing one. `attempts` caps how many
 * times an operation is re-run; `deadlineMs` caps the wall clock, because an attempt is not free: against a
 * dead master it costs a connect timeout, a read timeout, phpredis' own socket retries and a discovery sweep
 * across every sentinel before it even fails. Counting attempts alone understates the real worst case by an
 * order of magnitude, and the whole point of the budget is to ride out a promotion without ever outliving an
 * fpm request or a load-balancer probe.
 */
class SentinelRetryPolicy
{
    /**
     * Exception-message fragments treated as "the master is gone, rediscover and retry".
     *
     * Matched case-insensitively, and deliberately a strict superset of the framework's own list in
     * PhpRedisConnection::command() (`went away`, `socket`, `Error while reading`, `read error on connection`,
     * `READONLY`, `Connection lost`) - the connection bypasses that vendor handling, so anything it used to
     * heal has to be healed here instead. Adapted from namoshek/laravel-redis-sentinel (MIT).
     *
     * `NOREPLICAS` is deliberately absent. A master refusing writes for want of a good replica is not a
     * transient promotion artifact: after a failover the new master has no replica at all until the demoted
     * node reattaches and finishes resyncing, which outlasts any budget worth having, and during a plain
     * replica outage retrying would add the full deadline to every single write. It fails fast and shows up
     * on the `sentinel` health probe instead.
     *
     * @var list<string>
     */
    private const array RETRYABLE_ERROR_FRAGMENTS = [
        "can't write against a read only replica",
        'broken pipe',
        'connection closed',
        'connection lost',
        'connection refused',
        'connection reset',
        'connection timed out',
        'error while reading',
        'failed while reconnecting',
        'getaddrinfo',
        'is loading the dataset in memory',
        'masterdown',
        'name or service not known',
        'php_network_getaddresses',
        'read error on connection',
        'readonly',
        'socket',
        'went away',
    ];

    /**
     * Whether retries are suppressed for the current call stack; see withoutRetries().
     */
    private static bool $suppressed = false;

    /**
     * @param  int  $attempts  Retries after the initial failure (`retry_attempts`).
     * @param  int  $delayMs  Wait between attempts in milliseconds (`retry_delay`).
     * @param  int  $deadlineMs  Total wall clock for one recovery (`retry_deadline`); 0 disables the bound.
     * @param  bool  $blocking  Whether the operation blocks indefinitely by design; see forBlockingOperations().
     */
    public function __construct(
        private readonly int $attempts = 3,
        private readonly int $delayMs = 500,
        private readonly int $deadlineMs = 5000,
        private readonly bool $blocking = false,
    ) {
    }

    /**
     * Build the policy from a `database.redis.*` connection configuration.
     *
     * @param  array  $config  The connection configuration, discovery keys included.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            max((int) ($config['retry_attempts'] ?? 3), 0),
            max((int) ($config['retry_delay'] ?? 500), 0),
            max((int) ($config['retry_deadline'] ?? 5000), 0),
        );
    }

    /**
     * Run a callback with every sentinel retry suppressed, connecting included.
     *
     * For callers that must report the state of the world rather than heal it - the health probes behind /up.
     *
     * Ambient rather than per-connection, because the expensive half happens before any connection object
     * exists to configure: opening a connection is itself retried, and RedisManager snapshots its config when
     * it is constructed, so by the time a probe holds a Connection its budget has already been spent. On a
     * cold fpm worker - which every /up request is - the probes resolve `cache` and `sessions` independently
     * and a failed connect is never pooled, so leaving connect retries in place costs two full deadlines.
     * That is long enough that a load balancer sees a probe timeout instead of a clean 500, while the worker
     * stays held for the duration; during an election that is how a Redis blip becomes worker exhaustion.
     *
     * Process-scoped and restored in a finally, so it cannot leak past the callback even on an exception.
     */
    public static function withoutRetries(Closure $callback): mixed
    {
        $previous = self::$suppressed;

        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    /**
     * A copy for operations that block indefinitely by design - (p)subscribe, and nothing else so far.
     *
     * Both halves of the ordinary budget quietly stop meaning anything for a subscription, because both are
     * scoped to one run() call and a subscriber's run() call lasts as long as the process:
     *
     *  - the deadline measures time since the operation began, which for a subscriber is almost entirely
     *    *healthy* blocked time. A subscription older than `retry_deadline` would get zero retries on its
     *    first failover - which is every real subscriber.
     *  - the attempt counter accumulates across the whole process, so a subscriber would survive exactly
     *    `retry_attempts` failovers in its lifetime and then die on the next one, however many hours apart.
     *
     * Neither is what the budget is for: it exists to bound one *recovery*, not one operation. So the deadline
     * is not enforced here, and the budget resets whenever an attempt ran long enough to have been doing work
     * rather than failing on the way up - a re-subscription that lasted is proof the previous incident is over.
     */
    public function forBlockingOperations(): self
    {
        return new self($this->attempts, $this->delayMs, $this->deadlineMs, blocking: true);
    }

    /**
     * Whether the current call stack is running with retries suppressed.
     *
     * Exposed so callers that must run under suppression can be pinned to it - the wiring is easy to delete
     * and impossible to notice by reading the probe, since a suppressed probe and an unsuppressed one differ
     * only in how long they take to fail.
     */
    public static function retriesSuppressed(): bool
    {
        return self::$suppressed;
    }

    /**
     * Run an operation, re-running it while the failure looks like a failover in progress.
     *
     * @param  callable(): mixed  $operation  The client operation.
     * @param  callable(): void  $onRetry  Runs between attempts; forced rediscovery lives here.
     * @param  string  $context  Names the caller in the log lines and the give-up message.
     * @return mixed The first successful result.
     *
     * @throws SentinelFailoverException When the attempt budget or the deadline is spent.
     * @throws Throwable Anything that is not failover-class, propagated untouched.
     */
    public function run(callable $operation, callable $onRetry, string $context): mixed
    {
        $attempts = 0;
        $startedAt = hrtime(true);

        while (true) {
            $attemptStartedAt = hrtime(true);

            try {
                return $operation();
            } catch (Throwable $exception) {
                if (!$this->isRetryable($exception)) {
                    throw $exception;
                }

                if ($this->attemptDidWork($attemptStartedAt)) {
                    $attempts = 0;
                    $startedAt = hrtime(true);
                }

                $elapsedMs = $this->elapsedMs($startedAt);

                if (self::$suppressed || $attempts >= $this->attempts || $this->deadlineWouldPass($elapsedMs)) {
                    throw new SentinelFailoverException(sprintf(
                        'Redis sentinel %s gave up after %d %s and %dms: %s',
                        $context,
                        $attempts,
                        $attempts === 1 ? 'retry' : 'retries',
                        $elapsedMs,
                        $exception->getMessage(),
                    ), 0, $exception);
                }

                $attempts++;

                Log::warning(sprintf(
                    'Redis sentinel %s: retryable failure (attempt %d/%d, %dms elapsed), rediscovering master: %s',
                    $context,
                    $attempts,
                    $this->attempts,
                    $elapsedMs,
                    $exception->getMessage(),
                ));

                if ($this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }

                $onRetry();
            }
        }
    }

    /**
     * Whether the failure signals master loss or demotion rather than an application error.
     */
    public function isRetryable(Throwable $exception): bool
    {
        // A budget that is already spent cannot be spent again; guards against nesting two policies.
        if ($exception instanceof SentinelFailoverException) {
            return false;
        }

        if ($exception instanceof SentinelDiscoveryException) {
            return $exception->anySentinelAnswered;
        }

        if (!$exception instanceof RedisException) {
            return false;
        }

        return Str::contains($exception->getMessage(), self::RETRYABLE_ERROR_FRAGMENTS, ignoreCase: true);
    }

    /**
     * Whether another attempt would outlive the deadline.
     *
     * The delay counts: sleeping into the deadline and then trying anyway spends the caller's time on an
     * attempt the budget has already ruled out. Blocking operations are exempt - there the elapsed time is
     * the subscription's own healthy lifetime, not effort spent recovering.
     */
    private function deadlineWouldPass(int $elapsedMs): bool
    {
        return !$this->blocking && $this->deadlineMs > 0 && $elapsedMs + $this->delayMs >= $this->deadlineMs;
    }

    /**
     * Whether the attempt that just failed had been doing work rather than failing to get started.
     *
     * The yardstick is the recovery deadline itself, which needs no extra configuration to justify: an attempt
     * that outlived the entire budget allowed for recovering cannot have been recovery. Getting it wrong in
     * either direction costs one retry, never correctness.
     *
     * Only consulted for blocking operations - an ordinary command that takes this long and then fails is
     * exactly the pathology the deadline exists to stop, not progress to be rewarded with a fresh budget.
     */
    private function attemptDidWork(int|float $attemptStartedAt): bool
    {
        return $this->blocking && $this->elapsedMs($attemptStartedAt) >= max($this->deadlineMs, 1000);
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
