<?php

namespace Tests\Feature\Ops;

use RuntimeException;
use Tests\TestCase;

/**
 * The REDIS_TOPOLOGY switch reshapes config/database.php and config/queue.php; the files are
 * re-evaluated here under each topology because the suite's own config is already loaded and
 * cached. Every test pins REDIS_TOPOLOGY explicitly so a developer's local .env cannot bleed in.
 */
class RedisTopologyConfigTest extends TestCase
{
    /**
     * Re-evaluate a config file with the given variables in the environment.
     *
     * Both superglobals are written and then restored, because env() reads
     * $_ENV before $_SERVER and the developer's .env populates both - a
     * one-sided override would lose to it.
     *
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function loadConfig(string $file, array $env): array
    {
        $previous = [];

        foreach ($env as $key => $value) {
            $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return require base_path("config/{$file}.php");
        } finally {
            foreach ($previous as $key => [$envValue, $serverValue]) {
                if ($envValue === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envValue;
                }

                if ($serverValue === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverValue;
                }
            }
        }
    }

    public function test_standalone_mode_keeps_dedicated_database_indexes(): void
    {
        $redis = $this->loadConfig('database', ['REDIS_TOPOLOGY' => 'standalone'])['redis'];

        $this->assertArrayNotHasKey('clusters', $redis);
        $this->assertSame('phpredis', $redis['client']);

        foreach (['default', 'cache', 'sessions', 'queue'] as $name) {
            $this->assertArrayHasKey($name, $redis);
            $this->assertArrayNotHasKey('sentinel_hosts', $redis[$name]);
        }

        $this->assertSame('3', $redis['queue']['database']);
    }

    public function test_sentinel_mode_is_standalone_semantics_with_discovery_bolted_on(): void
    {
        $redis = $this->loadConfig('database', [
            'REDIS_TOPOLOGY' => 'sentinel',
            'REDIS_SENTINEL_HOSTS' => '10.0.0.1:26379,10.0.0.2:26379',
        ])['redis'];

        $this->assertArrayNotHasKey('clusters', $redis);
        $this->assertSame('phpredis-sentinel', $redis['client'], 'the app-owned driver must be selected');

        $databases = ['default' => '0', 'cache' => '1', 'sessions' => '2', 'queue' => '3'];

        foreach ($databases as $name => $database) {
            $this->assertArrayHasKey($name, $redis);
            $this->assertSame($database, $redis[$name]['database'], "DB-index isolation must survive on [{$name}]");
            $this->assertSame('10.0.0.1:26379,10.0.0.2:26379', $redis[$name]['sentinel_hosts']);
            $this->assertSame('mymaster', $redis[$name]['sentinel_service']);
            $this->assertArrayHasKey('retry_attempts', $redis[$name]);
            $this->assertArrayHasKey('retry_delay', $redis[$name]);

            // The wall-clock bound, and the only one that accounts for what an attempt actually costs
            // (connect timeout + read timeout + a discovery sweep). Without it a failover can outlive an
            // FPM request no matter how modest attempts x delay looks.
            $this->assertGreaterThan(0, (int) $redis[$name]['retry_deadline']);

            /*
             * Pins the composition operator: $sentinel() overrides max_retries, and `+` would silently keep
             * the standalone value because the key already exists on the left-hand array. Letting phpredis
             * retry a dead master on its own only multiplies every attempt before the app budget sees it.
             */
            $this->assertSame(1, (int) $redis[$name]['max_retries'], 'the sentinel override must survive composition');

            // Bounded socket timeouts are load-bearing for failover: infinite (phpredis' 0
            // default) turns a dying master into request hangs instead of retryable errors.
            $this->assertGreaterThan(0, (float) $redis[$name]['timeout']);
            $this->assertGreaterThan(0, (float) $redis[$name]['read_timeout']);
        }
    }

    public function test_standalone_keeps_the_stock_phpredis_retry_count(): void
    {
        // The max_retries drop is a sentinel-only trade; standalone has no app-level loop to defer to.
        $redis = $this->loadConfig('database', ['REDIS_TOPOLOGY' => 'standalone'])['redis'];

        $this->assertSame(3, (int) $redis['cache']['max_retries']);
        $this->assertArrayNotHasKey('retry_deadline', $redis['cache']);
    }

    public function test_cluster_mode_moves_the_connections_under_clusters(): void
    {
        $redis = $this->loadConfig('database', ['REDIS_TOPOLOGY' => 'cluster'])['redis'];

        foreach (['default', 'cache', 'sessions', 'queue'] as $name) {
            $this->assertArrayNotHasKey($name, $redis, "a top-level '{$name}' would shadow its cluster twin");
            $this->assertArrayHasKey($name, $redis['clusters']);
        }

        $sessionsPrefix = $redis['clusters']['sessions']['options']['prefix'];

        $this->assertNotSame('', $sessionsPrefix);
        $this->assertNotSame(
            $redis['options']['prefix'],
            $sessionsPrefix,
            'auth:flush-sessions needs a sessions prefix distinct from the shared one'
        );
    }

    public function test_an_unknown_topology_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported REDIS_TOPOLOGY [bogus]');

        $this->loadConfig('database', ['REDIS_TOPOLOGY' => 'bogus']);
    }

    public function test_the_cluster_queue_name_carries_a_hash_tag(): void
    {
        $connection = $this->loadConfig('queue', ['REDIS_TOPOLOGY' => 'cluster'])['connections']['redis'];

        $this->assertSame('{default}', $connection['queue']);
        $this->assertSame('queue', $connection['connection']);
    }

    public function test_the_standalone_queue_name_is_untagged(): void
    {
        $connection = $this->loadConfig('queue', ['REDIS_TOPOLOGY' => 'standalone'])['connections']['redis'];

        $this->assertSame('default', $connection['queue']);
        $this->assertSame('queue', $connection['connection']);
    }

    public function test_the_sentinel_queue_name_is_untagged_like_standalone(): void
    {
        $connection = $this->loadConfig('queue', ['REDIS_TOPOLOGY' => 'sentinel'])['connections']['redis'];

        $this->assertSame('default', $connection['queue'], 'a master/replica pair is one keyspace - no hash tag');
    }
}
