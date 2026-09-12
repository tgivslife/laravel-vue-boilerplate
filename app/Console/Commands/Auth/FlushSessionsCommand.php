<?php

namespace App\Console\Commands\Auth;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Signs every user out at once: empties every remember token, destroys every session in the configured store and
 * clears the session registry, in that order, so no remembered browser signs straight back in.
 * Personal access tokens are outside its scope. The incident lever after a leaked APP_KEY or a compromised account.
 *
 * How the store is emptied depends on the session driver:
 *  - database: deletes every row of the sessions table.
 *  - redis, standalone or sentinel: FLUSHDB on the database behind `session.connection`. Session keys carry no
 *    distinguishing pattern, so the whole database goes - safe only because the dedicated `sessions` connection
 *    (SESSION_CONNECTION) keeps them on their own index; refused when the queue or cache shares it.
 *  - redis, cluster: no database indexes, so isolation comes from the connection's own key prefix, and each master
 *    node is SCAN-swept under it. Refused without a dedicated prefix, and with a predis client, since the sweep
 *    drives per-node RedisCluster commands.
 *  - any other driver: refused, their storage cannot be enumerated and cleared reliably from here.
 *
 * Confirmation follows artisan migrate: production shows an alert plus a prompt that --force bypasses, other environments a plain prompt.
 * A non-interactive production run without --force fails safe, the prompt defaulting to no.
 */
#[Signature('auth:flush-sessions {--force : Force the operation to run without a confirmation prompt}')]
#[Description('Destroy every session in the configured session store, forcing all users to sign in again')]
class FlushSessionsCommand extends Command
{
    use ConfirmableTrait;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $driver = (string) config('session.driver');

        if (!in_array($driver, ['database', 'redis'], true)) {
            $this->error("Flushing the '{$driver}' session driver is not supported.");

            return self::FAILURE;
        }

        $connection = (string) (config('session.connection') ?? 'default');
        $isCluster = $driver === 'redis' && $this->isClusterConnection($connection);

        if ($driver === 'redis' && !$isCluster && ($conflicts = $this->redisCotenants()) !== []) {
            $this->error(
                'The session redis connection is shared with: '.implode(', ', $conflicts).'. '
                .'FLUSHDB would delete their data too - point SESSION_CONNECTION at the dedicated '
                ."'sessions' connection first."
            );

            return self::FAILURE;
        }

        if ($isCluster && config('database.redis.client') !== 'phpredis') {
            $this->error(
                'Flushing sessions on a Redis Cluster requires the phpredis client - '
                .'the sweep drives per-node RedisCluster commands (_masters, scan).'
            );

            return self::FAILURE;
        }

        if ($isCluster && !$this->clusterPrefixIsolatesSessions($connection)) {
            $this->error(
                "The '{$connection}' cluster connection has no dedicated key prefix, so a sweep "
                .'would delete co-tenant data too - give it one (REDIS_SESSIONS_PREFIX) first.'
            );

            return self::FAILURE;
        }

        // The production guard, then the lighter prompt that keeps accidental local runs honest without the alert banner.
        if (!$this->confirmToProceed('Application In Production - Every User Will Be Signed Out')) {
            return self::FAILURE;
        }

        if (!$this->laravel->isProduction()
            && !$this->option('force')
            && !$this->confirm('Every user will be signed out. Continue?')) {
            $this->comment('Aborted.');

            return self::FAILURE;
        }

        // Remember tokens first, so a recaller arriving between the steps meets a dead token; an empty token is refused
        // by both user providers, and the next remembered sign-in mints a new one.
        $forgotten = DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);

        $this->info("Invalidated {$forgotten} remember-me tokens.");

        if ($driver === 'database') {
            $deleted = DB::table((string) config('session.table', 'sessions'))->delete();

            $this->info("Deleted {$deleted} sessions.");
        } elseif ($isCluster) {
            $deleted = $this->flushClusterSessions($connection);

            $this->info("Deleted {$deleted} session keys from the cluster.");
        } else {
            Redis::connection($connection)->flushdb();

            $this->info("Flushed the redis database behind the 'session.connection' connection.");
        }

        $registryRows = DB::table('user_sessions')->delete();

        $this->info("Cleared {$registryRows} session registry entries.");

        return self::SUCCESS;
    }

    /**
     * Whether the named redis connection resolves to a cluster; as in RedisManager, a standalone connection of the same name wins.
     */
    private function isClusterConnection(string $connection): bool
    {
        return config("database.redis.{$connection}") === null
            && config("database.redis.clusters.{$connection}") !== null;
    }

    /**
     * Whether the cluster connection carries a key prefix of its own, distinct from the shared client prefix;
     * without one, a prefix sweep is as indiscriminate as FLUSHDB.
     */
    private function clusterPrefixIsolatesSessions(string $connection): bool
    {
        $prefix = (string) config("database.redis.clusters.{$connection}.options.prefix");

        return $prefix !== '' && $prefix !== (string) config('database.redis.options.prefix');
    }

    /**
     * Delete every session key on the cluster, master node by master node, since SCAN and DEL are per-node there.
     *
     * SCAN matches raw server-side keys (phpredis does not apply OPT_PREFIX to the pattern), while the DEL goes back
     * through the client, which re-applies the prefix - hence the strip.
     */
    private function flushClusterSessions(string $connection): int
    {
        $prefix = (string) config("database.redis.clusters.{$connection}.options.prefix");
        $redis = Redis::connection($connection);
        $client = $redis->client();
        $deleted = 0;

        foreach ($client->_masters() as $master) {
            $iterator = null;

            do {
                $keys = $client->scan($iterator, $master, $prefix.'*', 500);

                foreach ($keys ?: [] as $key) {
                    $deleted += (int) $redis->command('del', [substr($key, strlen($prefix))]);
                }
            } while ($iterator > 0);
        }

        return $deleted;
    }

    /**
     * Redis consumers configured on the same connection as sessions.
     * The stock connections are dedicated, but a null session connection resolves to `default` and any of them can be repointed by env.
     *
     * @return list<string>
     */
    private function redisCotenants(): array
    {
        $sessionConnection = (string) (config('session.connection') ?? 'default');

        $consumers = [
            'the redis queue' => (string) config('queue.connections.redis.connection', 'queue'),
            'the redis cache store' => (string) config('cache.stores.redis.connection', 'cache'),
        ];

        return array_keys(array_filter(
            $consumers,
            static fn(string $connection): bool => $connection === $sessionConnection,
        ));
    }
}
