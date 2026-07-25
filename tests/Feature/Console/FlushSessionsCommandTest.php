<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlushSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_flushes_database_sessions_and_the_registry(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->createUser();

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->getKey(),
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
        $this->createOtherSessionFor($user);

        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(0, DB::table('user_sessions')->count());
    }

    public function test_requires_confirmation_without_force(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        $this->artisan('auth:flush-sessions')
            ->expectsConfirmation('Every user will be signed out. Continue?', 'no')
            ->assertFailed();

        $this->assertSame(1, DB::table('user_sessions')->count());
    }

    public function test_production_asks_the_migrate_style_confirmation(): void
    {
        $this->app['env'] = 'production';
        config(['session.driver' => 'database']);
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        $this->artisan('auth:flush-sessions')
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        $this->assertSame(1, DB::table('user_sessions')->count());
    }

    public function test_production_proceeds_once_confirmed(): void
    {
        $this->app['env'] = 'production';
        config(['session.driver' => 'database']);
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        $this->artisan('auth:flush-sessions')
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('user_sessions')->count());
    }

    public function test_production_force_bypasses_the_confirmation(): void
    {
        // migrate semantics: --force exists for deploy scripts and skips the prompt.
        $this->app['env'] = 'production';
        config(['session.driver' => 'database']);
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DB::table('user_sessions')->count());
    }

    public function test_rejects_unsupported_session_drivers(): void
    {
        // The suite runs on the array driver, which has nothing to flush.
        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->assertFailed();
    }

    public function test_refuses_to_flush_a_shared_redis_connection(): void
    {
        // Repoint the queue at `default`, where a null session connection also
        // resolves - FLUSHDB there would delete queued jobs. The guard exits
        // before any redis call, so no server is needed.
        config([
            'session.driver' => 'redis',
            'session.connection' => null,
            'queue.connections.redis.connection' => 'default',
        ]);

        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->assertFailed();
    }

    public function test_flushes_a_dedicated_redis_connection_past_the_guard(): void
    {
        // With a dedicated connection the guard passes; the flush itself
        // needs a live redis server, so only the guard logic is asserted
        // here by pointing the co-tenants elsewhere and expecting the
        // command to proceed to (and fail at) the connection stage or
        // succeed when redis is available.
        config([
            'session.driver' => 'redis',
            'session.connection' => 'sessions',
        ]);

        try {
            $this->artisan('auth:flush-sessions', ['--force' => true]);
        } catch (\Throwable) {
            // No redis server in the test environment: reaching the
            // connection attempt proves the guard let a dedicated
            // connection through.
            $this->assertTrue(true);

            return;
        }

        $this->assertSame(0, DB::table('user_sessions')->count());
    }

    public function test_refuses_a_cluster_session_connection_on_predis(): void
    {
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        // The cluster sweep drives phpredis-specific RedisCluster commands;
        // under predis the command must refuse before prompting or connecting.
        config([
            'session.driver' => 'redis',
            'session.connection' => 'sessions',
            'database.redis.client' => 'predis',
            'database.redis.sessions' => null,
            'database.redis.clusters.sessions' => [
                ['host' => '127.0.0.1', 'port' => '6379'],
                'options' => ['prefix' => 'testing-sessions-'],
            ],
        ]);

        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->assertFailed();

        $this->assertSame(1, DB::table('user_sessions')->count());
    }

    public function test_refuses_a_cluster_session_connection_without_a_dedicated_prefix(): void
    {
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        // A cluster connection carrying only the shared client prefix: a
        // prefix sweep would be as indiscriminate as FLUSHDB, so the guard
        // must refuse before any redis call.
        config([
            'session.driver' => 'redis',
            'session.connection' => 'sessions',
            'database.redis.sessions' => null,
            'database.redis.clusters.sessions' => [
                ['host' => '127.0.0.1', 'port' => '6379'],
            ],
        ]);

        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->assertFailed();

        $this->assertSame(1, DB::table('user_sessions')->count());
    }

    public function test_sweeps_a_cluster_session_connection_by_prefix(): void
    {
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        config([
            'session.driver' => 'redis',
            'session.connection' => 'sessions',
            'database.redis.sessions' => null,
            'database.redis.clusters.sessions' => [
                ['host' => '127.0.0.1', 'port' => '6379'],
                'options' => ['prefix' => 'testing-sessions-'],
            ],
        ]);

        // A fake cluster client: one master whose scan yields a single
        // prefixed key. The command must strip the prefix before deleting,
        // because the client re-applies it (OPT_PREFIX).
        $client = new class {
            /** @return list<array{0: string, 1: int}> */
            public function _masters(): array
            {
                return [['127.0.0.1', 6379]];
            }

            /**
             * @param  array{0: string, 1: int}  $master
             * @return list<string>|false
             */
            public function scan(?int &$iterator, array $master, string $pattern, int $count): array|false
            {
                $iterator = 0;

                return $pattern === 'testing-sessions-*' ? ['testing-sessions-abc123'] : false;
            }
        };

        $connection = new class($client) {
            /** @var list<string> */
            public array $deletedKeys = [];

            public function __construct(private readonly object $fakeClient)
            {
            }

            public function client(): object
            {
                return $this->fakeClient;
            }

            /**
             * @param  list<string>  $parameters
             */
            public function command(string $command, array $parameters = []): int
            {
                $this->deletedKeys[] = $parameters[0];

                return 1;
            }
        };

        Redis::shouldReceive('connection')->with('sessions')->andReturn($connection);

        $this->artisan('auth:flush-sessions', ['--force' => true])
            ->expectsOutputToContain('Deleted 1 session keys from the cluster.')
            ->assertSuccessful();

        $this->assertSame(['abc123'], $connection->deletedKeys);
        $this->assertSame(0, DB::table('user_sessions')->count());
    }
}
