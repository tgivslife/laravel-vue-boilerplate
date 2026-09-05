<?php

namespace Tests\Unit\Support\Redis;

use App\Support\Redis\SentinelDiscoveryException;
use App\Support\Redis\SentinelFailoverException;
use App\Support\Redis\SentinelRetryPolicy;
use Illuminate\Support\Facades\Log;
use RedisException;
use RuntimeException;
use Tests\TestCase;

/**
 * The failover budget as pure logic - no sockets, no clients.
 *
 * Everything the connector and the connection agree on lives here: what counts as "the master moved", how
 * long the loop may spend deciding, and what comes out the other end when it gives up.
 */
class SentinelRetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every retry the suite provokes logs a warning by design; the spy keeps that
        // expected noise out of laravel.log, so anything landing there is a real anomaly.
        Log::spy();
    }

    private function policy(int $attempts = 3, int $delayMs = 0, int $deadlineMs = 0): SentinelRetryPolicy
    {
        return new SentinelRetryPolicy($attempts, $delayMs, $deadlineMs);
    }

    public function test_it_retries_a_failover_class_failure_until_the_operation_succeeds(): void
    {
        $calls = 0;
        $retries = 0;

        $result = $this->policy()->run(
            function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new RedisException("READONLY You can't write against a read only replica.");
                }

                return 'PONG';
            },
            function () use (&$retries): void {
                $retries++;
            },
            'test',
        );

        $this->assertSame('PONG', $result);
        $this->assertSame(2, $retries, 'rediscovery must run once between each pair of attempts');
    }

    public function test_it_recognises_every_shape_a_failover_produces(): void
    {
        $policy = $this->policy();

        $messages = [
            'demoted master' => "READONLY You can't write against a read only replica.",
            'dead master' => 'Connection refused',
            'just-promoted replica' => 'LOADING Redis is loading the dataset in memory',
            'replica whose master link is down' => "MASTERDOWN Link with MASTER is down and replica-serve-stale-data is set to 'no'",
            'torn-down socket' => 'Connection reset by peer',
            'half-closed socket' => 'Broken pipe',
            'gone' => 'Redis server went away',
            // In the framework's own list but historically absent from ours; the connection bypasses the
            // vendor's handling, so missing it here would be an outright regression rather than a gap.
            'vendor parity' => 'Error while reading line from the server',
            'matched without regard to case' => 'CONNECTION REFUSED by peer',
        ];

        foreach ($messages as $label => $message) {
            $this->assertTrue($policy->isRetryable(new RedisException($message)), $label);
        }
    }

    public function test_application_errors_are_not_retryable(): void
    {
        $policy = $this->policy();

        foreach ([
                     'WRONGTYPE Operation against a key holding the wrong kind of value',
                     'OOM command not allowed when used memory > maxmemory.',
                     'NOAUTH Authentication required.',
                 ] as $message) {
            $this->assertFalse($policy->isRetryable(new RedisException($message)), $message);
        }
    }

    public function test_noreplicas_is_deliberately_not_retryable(): void
    {
        /*
         * min-replicas-to-write makes a master refuse writes while it has no good replica, which after a
         * promotion lasts for the whole resync - far longer than any budget. Retrying would add the deadline
         * to every write of a plain replica outage and still fail. It belongs on the sentinel health probe,
         * not in the retry loop; see docs/redis.md.
         */
        $this->assertFalse(
            $this->policy()->isRetryable(new RedisException('NOREPLICAS Not enough good replicas to write.')),
        );
    }

    public function test_a_discovery_failure_is_retryable_only_while_sentinels_are_answering(): void
    {
        $policy = $this->policy();

        $this->assertTrue(
            $policy->isRetryable(new SentinelDiscoveryException('no master yet', anySentinelAnswered: true)),
            'sentinels answering but naming no master is an election in flight',
        );

        $this->assertFalse(
            $policy->isRetryable(new SentinelDiscoveryException('fleet unreachable', anySentinelAnswered: false)),
            'an unreachable fleet does not become reachable by waiting',
        );
    }

    public function test_a_spent_budget_is_never_retried_again(): void
    {
        // Guards against two policies nesting: the give-up message quotes the original, retryable, error.
        $this->assertFalse($this->policy()->isRetryable(
            new SentinelFailoverException('gave up after 3 retries: Connection refused'),
        ));
    }

    public function test_the_deadline_ends_the_loop_even_with_attempts_to_spare(): void
    {
        $calls = 0;

        try {
            (new SentinelRetryPolicy(50, 0, 150))->run(
                function () use (&$calls): void {
                    $calls++;
                    usleep(80_000);

                    throw new RedisException('Connection refused');
                },
                static fn() => null,
                'test',
            );

            $this->fail('Expected the deadline to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertLessThan(10, $calls, 'the attempt budget must not be what stopped this');
            $this->assertStringContainsString('gave up', $exception->getMessage());
        }
    }

    public function test_exhaustion_throws_a_redis_exception_carrying_the_original(): void
    {
        $original = new RedisException('Connection lost');

        try {
            $this->policy(2)->run(
                static fn() => throw $original,
                static fn() => null,
                'connection [cache]',
            );

            $this->fail('Expected the budget to exhaust.');
        } catch (SentinelFailoverException $exception) {
            // A RedisException subclass, so `catch (RedisException)` around Redis work still catches it.
            $this->assertInstanceOf(RedisException::class, $exception);
            $this->assertSame($original, $exception->getPrevious());
            $this->assertStringContainsString('connection [cache]', $exception->getMessage());
            $this->assertStringContainsString('gave up after 2 retries', $exception->getMessage());
        }
    }

    public function test_non_redis_failures_propagate_untouched(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a redis problem');

        $this->policy()->run(
            static fn() => throw new RuntimeException('not a redis problem'),
            static fn() => null,
            'test',
        );
    }

    public function test_a_subscription_older_than_the_deadline_still_gets_its_retries(): void
    {
        /*
         * The deadline measures time since the operation began, and for a subscriber that is almost entirely
         * healthy blocked time. Applied literally it gave every subscription older than `retry_deadline` -
         * which is every real subscriber - exactly zero retries on its first failover.
         */
        $attempts = 0;

        $result = $this->policy(3, 0, 1000)->forBlockingOperations()->run(
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    usleep(1_050_000); // a subscription that outlived the deadline doing its job

                    throw new RedisException('Connection lost');
                }

                return 'resubscribed';
            },
            static fn() => null,
            'connection [broadcast]',
        );

        $this->assertSame('resubscribed', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_a_blocking_budget_does_not_accumulate_across_separate_incidents(): void
    {
        /*
         * A subscriber's run() call lasts as long as the process, so a per-call attempt counter would let it
         * survive exactly `retry_attempts` failovers in its lifetime and then die on the next one, however many
         * hours apart. One retry allowed here, two incidents survived.
         */
        $incidents = 0;

        $result = $this->policy(1, 0, 1000)->forBlockingOperations()->run(
            function () use (&$incidents): string {
                $incidents++;

                if ($incidents > 2) {
                    return 'clean unsubscribe';
                }

                usleep(1_050_000); // established, worked, then a failover ended it

                throw new RedisException('Connection lost');
            },
            static fn() => null,
            'connection [broadcast]',
        );

        $this->assertSame('clean unsubscribe', $result);
        $this->assertSame(3, $incidents, 'a re-subscription that lasted proves the previous incident is over');
    }

    public function test_a_subscription_that_never_establishes_still_gives_up(): void
    {
        // The reset is earned by doing work. Failing immediately, over and over, must not loop forever.
        $attempts = 0;

        try {
            $this->policy(2, 0, 1000)->forBlockingOperations()->run(
                function () use (&$attempts): void {
                    $attempts++;

                    throw new RedisException('Connection refused');
                },
                static fn() => null,
                'connection [broadcast]',
            );

            $this->fail('Expected an unestablishable subscription to give up.');
        } catch (SentinelFailoverException) {
            $this->assertSame(3, $attempts, 'the initial attempt plus its two retries, then done');
        }
    }

    public function test_an_ordinary_command_is_not_granted_blocking_semantics(): void
    {
        // A command that runs this long and then fails is the pathology the deadline exists to stop, not
        // progress to be rewarded with a fresh budget.
        $attempts = 0;

        try {
            $this->policy(3, 0, 1000)->run(
                function () use (&$attempts): void {
                    $attempts++;
                    usleep(1_050_000);

                    throw new RedisException('Connection lost');
                },
                static fn() => null,
                'connection [cache]',
            );

            $this->fail('Expected the deadline to end the loop.');
        } catch (SentinelFailoverException $exception) {
            $this->assertSame(1, $attempts);
            $this->assertStringContainsString('gave up after 0 retries', $exception->getMessage());
        }
    }

    public function test_suppression_makes_the_first_failure_final(): void
    {
        /*
         * Ambient rather than per-connection because /up's expensive half is opening the connection, which is
         * itself retried and happens before any Connection object exists to configure.
         */
        $calls = 0;

        try {
            // A regular closure, not an arrow function: the latter captures $calls by value, so the inner
            // by-reference binding would track a copy and this test would silently assert nothing.
            SentinelRetryPolicy::withoutRetries(function () use (&$calls): void {
                $this->policy()->run(
                    function () use (&$calls): void {
                        $calls++;

                        throw new RedisException('Connection refused');
                    },
                    static fn() => null,
                    'probe',
                );
            });

            $this->fail('Expected the first failure to be final.');
        } catch (SentinelFailoverException) {
            $this->assertSame(1, $calls);
        }
    }

    public function test_suppression_does_not_leak_past_the_callback(): void
    {
        // A leaked flag would silently disable failover recovery for the rest of the process.
        try {
            SentinelRetryPolicy::withoutRetries(static fn() => throw new RuntimeException('probe blew up'));
        } catch (RuntimeException) {
            // Expected.
        }

        $calls = 0;

        $this->policy()->run(
            function () use (&$calls): string {
                if (++$calls === 1) {
                    throw new RedisException('Connection refused');
                }

                return 'PONG';
            },
            static fn() => null,
            'connection [cache]',
        );

        $this->assertSame(2, $calls, 'retries must be back on once the probe returns');
    }

    public function test_suppression_nests_without_clobbering_the_outer_scope(): void
    {
        SentinelRetryPolicy::withoutRetries(function (): void {
            SentinelRetryPolicy::withoutRetries(static fn() => null);

            $calls = 0;

            try {
                $this->policy()->run(
                    function () use (&$calls): void {
                        $calls++;

                        throw new RedisException('Connection refused');
                    },
                    static fn() => null,
                    'probe',
                );

                $this->fail('The inner scope returning must not re-enable retries.');
            } catch (SentinelFailoverException) {
                $this->assertSame(1, $calls);
            }
        });
    }
}
