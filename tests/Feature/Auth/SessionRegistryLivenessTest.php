<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * The redis liveness check inside SessionRegistry::forUser(): a standalone
 * connection batches every EXISTS into one pipeline, while a cluster
 * connection must issue them one by one (a pipeline cannot be routed across
 * hash slots). The suite runs on the array session driver, so the redis
 * branches are exercised here through mocked stores.
 */
class SessionRegistryLivenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A SessionRegistry whose session handler sits on the given redis connection.
     */
    private function registryOn(object $connection): SessionRegistry
    {
        $store = Mockery::mock(RedisStore::class);
        $store->shouldReceive('getPrefix')->andReturn('prefix:');
        $store->shouldReceive('connection')->andReturn($connection);

        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn($store);

        $handler = Mockery::mock(CacheBasedSessionHandler::class);
        $handler->shouldReceive('getCache')->andReturn($cache);

        $driver = Mockery::mock(Store::class);
        $driver->shouldReceive('getHandler')->andReturn($handler);

        $sessions = Mockery::mock(SessionManager::class);
        $sessions->shouldReceive('driver')->andReturn($driver);

        return new SessionRegistry($sessions);
    }

    private function registerSession(User $user, string $sessionId, int $minutesAgo): void
    {
        DB::table('user_sessions')->insert([
            'user_id' => $user->getKey(),
            'session_id' => $sessionId,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0',
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cluster_connections_check_each_session_individually(): void
    {
        $user = $this->createUser();
        $this->registerSession($user, 'live-session-id', 1);
        $this->registerSession($user, 'dead-session-id', 2);

        $connection = Mockery::mock(PhpRedisClusterConnection::class);
        $connection->shouldReceive('exists')->once()->with('prefix:live-session-id')->andReturn(1);
        $connection->shouldReceive('exists')->once()->with('prefix:dead-session-id')->andReturn(0);
        $connection->shouldNotReceive('pipeline');

        $live = $this->registryOn($connection)->forUser($user);

        $this->assertSame(['live-session-id'], $live->pluck('session_id')->all());
        $this->assertSame(
            ['live-session-id'],
            DB::table('user_sessions')->pluck('session_id')->all(),
            'the dead session row should be pruned'
        );
    }

    public function test_standalone_connections_batch_the_check_into_one_pipeline(): void
    {
        $user = $this->createUser();
        $this->registerSession($user, 'live-session-id', 1);
        $this->registerSession($user, 'dead-session-id', 2);

        $connection = Mockery::mock(PhpRedisConnection::class);
        // Rows come back most recently active first, so the flags map onto
        // [live-session-id, dead-session-id].
        $connection->shouldReceive('pipeline')->once()->andReturn([1, 0]);
        $connection->shouldNotReceive('exists');

        $live = $this->registryOn($connection)->forUser($user);

        $this->assertSame(['live-session-id'], $live->pluck('session_id')->all());
    }
}
