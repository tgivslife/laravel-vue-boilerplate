<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\HealthCheckService;
use App\Support\Redis\SentinelRetryPolicy;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Pins that the redis-backed critical probes actually run under retry suppression.
 *
 * Worth its own test because the wiring is invisible from the outside: a suppressed probe and an unsuppressed
 * one differ only in how long they take to fail, so deleting the wrapper breaks nothing that anything else
 * asserts, and the symptom - /up holding an fpm worker for two full retry deadlines during an election, long
 * enough for the load balancer to see a timeout instead of a clean 500 - only appears under a real failover.
 *
 * Both observation points matter. The command has to be suppressed, obviously; but so does *resolving* the
 * connection, because opening one is itself retried and on a cold worker that is where the entire budget
 * goes. A wrapper that covers only the command would look correct and fix almost nothing.
 */
class HealthProbeRetrySuppressionTest extends TestCase
{
    /** Whether retries were suppressed when the manager was asked for a connection. */
    private ?bool $suppressedAtResolve = null;

    /** Whether retries were suppressed when a command reached the client. */
    private ?bool $suppressedAtCommand = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindRecordingRedis();
    }

    /**
     * Swap the redis factory for one that records the suppression state at both observation points.
     */
    private function bindRecordingRedis(): void
    {
        $client = new class($this->suppressedAtCommand)
        {
            public function __construct(public ?bool &$suppressedAtCommand) {}

            public function __call(string $method, array $arguments): mixed
            {
                $this->suppressedAtCommand ??= SentinelRetryPolicy::retriesSuppressed();

                // Enough of a redis to satisfy a cache round trip: setex/del report success, get returns
                // whatever put would have stored.
                return match ($method) {
                    'get' => 'ok',
                    default => true,
                };
            }
        };

        $connection = new class($client) extends \Illuminate\Redis\Connections\PhpRedisConnection
        {
            public function __construct($client)
            {
                parent::__construct($client, null, []);
            }
        };

        $factory = new class($connection, $this->suppressedAtResolve) implements Factory
        {
            public function __construct(
                private readonly object $connection,
                public ?bool &$suppressedAtResolve,
            ) {}

            public function connection($name = null)
            {
                $this->suppressedAtResolve ??= SentinelRetryPolicy::retriesSuppressed();

                return $this->connection;
            }
        };

        $this->app->instance('redis', $factory);
    }

    private function runProbe(string $name): array
    {
        foreach (app(HealthCheckService::class)->report() as $probe) {
            if ($probe['name'] === $name) {
                return $probe;
            }
        }

        $this->fail("The [{$name}] probe did not run.");
    }

    public function test_the_sessions_probe_resolves_and_queries_under_suppression(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'sessions']);

        $probe = $this->runProbe('sessions');

        $this->assertTrue($probe['ok']);
        $this->assertTrue($this->suppressedAtResolve, 'opening the connection must be suppressed too - that is where a cold worker spends the budget');
        $this->assertTrue($this->suppressedAtCommand);
    }

    public function test_the_cache_probe_resolves_and_queries_under_suppression(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        Cache::forgetDriver('redis');

        $probe = $this->runProbe('cache');

        $this->assertTrue($probe['ok']);
        $this->assertTrue($this->suppressedAtResolve);
        $this->assertTrue($this->suppressedAtCommand);
    }

    public function test_suppression_is_confined_to_the_probes(): void
    {
        // The flag is process-scoped, so a probe leaving it set would silently disable failover recovery for
        // the rest of the request.
        config(['session.driver' => 'redis', 'session.connection' => 'sessions']);

        app(HealthCheckService::class)->report(criticalOnly: true);

        $this->assertFalse(SentinelRetryPolicy::retriesSuppressed());
    }
}
