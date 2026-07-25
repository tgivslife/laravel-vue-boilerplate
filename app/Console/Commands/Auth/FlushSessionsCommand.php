<?php

namespace App\Console\Commands\Auth;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Destroys every session in the configured session store and clears the
 * session registry, signing every user out at once.
 *
 * Two uses: as a deployment step when the session registry shipped (sessions
 * created before it are not indexed and would otherwise survive
 * account-recovery purges until they expire), and as an incident lever -
 * "force re-login for everyone" after a leaked APP_KEY or a compromised
 * account.
 *
 * How the store is emptied depends on the session driver:
 *  - database: deletes every row of the sessions table.
 *
 *  - redis, standalone or sentinel: FLUSHDB on the database behind `session.connection`.
 *    Redis session keys carry no distinguishing pattern (a 40-character id is indistinguishable from a sha1 cache key),
 *    so the whole database goes - safe only because the dedicated `sessions` connection (config/database.php, SESSION_CONNECTION)
 *    keeps them on their own index;
 *    sentinel preserves that isolation, it only changes how the master is found.
 *    Refused when the queue or cache shares the connection, since FLUSHDB would take their data with it.
 *
 *  - redis, cluster (REDIS_TOPOLOGY=cluster): a cluster has no database indexes, so isolation comes from the connection's dedicated
 *    client-level key prefix instead, and each master node is SCAN-swept for keys under it.
 *    Refused when that prefix is missing or equal to the shared one - the sweep would then be as indiscriminate as FLUSHDB.
 *    phpredis only: the sweep drives per-node RedisCluster commands, so a predis client is refused up front.
 *  - any other driver (file, cookie, ...): refused;
 *    their storage cannot be enumerated and cleared reliably from here.
 *
 * Confirmation follows artisan migrate: production shows an alert plus a
 * prompt that --force bypasses (for deploy scripts), other environments get
 * a plain prompt. A non-interactive production run without --force fails
 * safe, because the prompt's default answer is no.
 *
 * Whichever branch ran, the user_sessions registry rows are deleted at the
 * end so the active-sessions views do not list dead sessions.
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

        /*
         * migrate-style production guard: alert + confirmation, bypassed by
         * --force, failing safe when non-interactive (default answer is no).
         * Outside production a lighter prompt keeps accidental local runs
         * honest without the alert banner.
         */
        if (!$this->confirmToProceed('Application In Production - Every User Will Be Signed Out')) {
            return self::FAILURE;
        }

        if (!$this->laravel->isProduction()
            && !$this->option('force')
            && !$this->confirm('Every user will be signed out. Continue?')) {
            $this->comment('Aborted.');

            return self::FAILURE;
        }

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
     * Whether the named redis connection resolves to a cluster - mirroring
     * RedisManager, where a standalone connection of the same name wins.
     */
    private function isClusterConnection(string $connection): bool
    {
        return config("database.redis.{$connection}") === null
            && config("database.redis.clusters.{$connection}") !== null;
    }

    /**
     * Whether the cluster connection carries its own key prefix, distinct from
     * the shared client prefix every other connection on the cluster uses -
     * without that, a prefix sweep is as indiscriminate as FLUSHDB.
     */
    private function clusterPrefixIsolatesSessions(string $connection): bool
    {
        $prefix = (string) config("database.redis.clusters.{$connection}.options.prefix");

        return $prefix !== '' && $prefix !== (string) config('database.redis.options.prefix');
    }

    /**
     * Delete every session key on the cluster, master node by master node.
     *
     * A cluster has no database index to FLUSHDB and both SCAN and DEL are
     * per-node operations. SCAN matches raw server-side keys (phpredis does
     * not apply OPT_PREFIX to the pattern), while the DEL goes back through
     * the client, which re-applies the prefix - hence the strip.
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
     *
     * The stock connections are dedicated (`sessions`, `cache`, `queue`),
     * but a null session connection resolves to `default` and any of them
     * can be repointed by env - so the overlap check stays.
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
