<?php

use App\Support\Redis\HostListParser;
use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Three topologies behind REDIS_TOPOLOGY (standalone | sentinel | cluster); a typo fails loudly
    | at boot/config:cache rather than silently falling back.
    |
    | standalone (default): direct connections, each isolated on its own database index.
    |
    | sentinel: the same connections and database indexes, with the current master discovered through
    | the sentinels (REDIS_SENTINEL_HOSTS) by the app-owned phpredis-sentinel driver (App\Support\Redis,
    | registered in AppServiceProvider) - phpredis itself has no Sentinel support and Laravel's stock
    | connector pins the host it first saw. Failover retry semantics and sizing: docs/redis.md.
    |
    | cluster: the same connection names are defined under `clusters` from the REDIS_CLUSTER_SEEDS
    | list (a name defined at the top level would shadow its cluster twin). Credentials and timeouts
    | live in the client options - node entries are reduced to host:port. No database indexes, so
    | sessions fall back to key-prefix isolation, and cache:clear (per-node FLUSHDB) would wipe every
    | co-tenant - see auth:flush-sessions before reaching for it.
    |
    */

    'redis' => (static function (): array {
        $topology = (string) env('REDIS_TOPOLOGY', 'standalone');
        $appSlug = Str::slug((string) env('APP_NAME', 'laravel'));

        $options = [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', $appSlug.'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ];

        /*
         * One shared standalone/sentinel connection body: sentinel keeps standalone's database-index
         * isolation intact - the connector merely rewrites host/port to the discovered master.
         */
        $connection = static fn(string $dbEnv, string $dbDefault): array => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env($dbEnv, $dbDefault),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ];

        $sentinel = static fn(array $base): array => array_merge($base, [
            /*
             * Never 0/infinite (the phpredis default): a dying master that accepts the handshake but never answers
             * must time out into the retry path, not hang the request.
             * read_timeout also caps blocking reads - PhpRedisSentinelConnection lifts it for the duration of a (p)subscribe.
             */
            'timeout' => env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 2.0),

            /*
             * phpredis' own same-socket reconnects, dialled down: the app-level loop owns recovery,
             * and phpredis retrying a dead master first only multiplies every attempt by its backoff.
             * One keeps blip-healing.
             */
            'max_retries' => env('REDIS_MAX_RETRIES', 1),

            'sentinel_hosts' => env('REDIS_SENTINEL_HOSTS', '127.0.0.1:26379'),
            'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
            'sentinel_username' => env('REDIS_SENTINEL_USERNAME'),
            'sentinel_password' => env('REDIS_SENTINEL_PASSWORD'),
            'sentinel_timeout' => env('REDIS_SENTINEL_TIMEOUT', 0.5),

            /*
             * The failover budget (App\Support\Redis\SentinelRetryPolicy), applied to opening a connection
             * as well as to running a command. retry_deadline is the wall-clock bound that actually protects
             * an FPM request (0 disables it - rarely what you want); sizing guidance: docs/redis.md.
             * Bounds are scoped to one recovery, so a healthy blocking (p)subscribe is exempt.
             */
            'retry_attempts' => env('REDIS_SENTINEL_RETRY_ATTEMPTS', 3),
            'retry_delay' => env('REDIS_SENTINEL_RETRY_DELAY', 500),
            'retry_deadline' => env('REDIS_SENTINEL_RETRY_DEADLINE', 5000),
        ]);

        /*
         * Cluster seed nodes (REDIS_CLUSTER_SEEDS, comma-separated; REDIS_HOST:REDIS_PORT as the single-seed fallback).
         * Every entry is only a boot candidate: phpredis connects to the first seed that answers and follows CLUSTER SLOTS from there,
         *  so listing all nodes just removes the one-seed boot dependency.
         * Credentials and timeouts do NOT belong on the nodes - the connector reduces each entry to host:port and discards the rest;
         * RedisCluster reads them from the client `options` below.
         */
        $clusterSeeds = static function (): array {
            $seeds = new HostListParser('REDIS_CLUSTER_SEEDS', 6379)->parseHosts(
                env('REDIS_CLUSTER_SEEDS')
                ?? env('REDIS_HOST', '127.0.0.1').':'.env('REDIS_PORT', '6379'),
            );

            if ($seeds === []) {
                throw new RuntimeException('REDIS_CLUSTER_SEEDS is empty - list at least one host:port seed node.');
            }

            return array_map(static fn(array $seed): array => [
                'host' => $seed[0],
                'port' => (string) $seed[1],
                'database' => '0',
            ], $seeds);
        };

        return match ($topology) {
            'standalone' => [
                'client' => env('REDIS_CLIENT', 'phpredis'),
                'options' => $options,

                'default' => $connection('REDIS_DB', '0'),
                'cache' => $connection('REDIS_CACHE_DB', '1'),

                /*
                 * Dedicated to sessions (SESSION_DRIVER=redis + SESSION_CONNECTION=sessions): its own
                 * database index lets auth:flush-sessions FLUSHDB without collateral damage.
                 */
                'sessions' => $connection('REDIS_SESSION_DB', '2'),

                /*
                 * The queue and Horizon (config/horizon.php `use`): its own index keeps jobs out of
                 * FLUSHDB reach of the cache and sessions.
                 */
                'queue' => $connection('REDIS_QUEUE_DB', '3'),
            ],

            /*
             * Identical connection bodies plus the discovery keys; `client` selects the app-owned
             * driver. host/port stay as harmless placeholders - the connector overwrites them.
             */
            'sentinel' => [
                'client' => 'phpredis-sentinel',
                'options' => $options,

                'default' => $sentinel($connection('REDIS_DB', '0')),
                'cache' => $sentinel($connection('REDIS_CACHE_DB', '1')),
                'sessions' => $sentinel($connection('REDIS_SESSION_DB', '2')),
                'queue' => $sentinel($connection('REDIS_QUEUE_DB', '3')),
            ],

            'cluster' => [
                'client' => env('REDIS_CLIENT', 'phpredis'),

                /*
                 * RedisCluster authenticates and times out at the client level, so credentials live here and not on the node entries,
                 * a password left on a node is silently discarded and surfaces as NOAUTH.
                 * Timeouts are bounded for the same reason as sentinel: phpredis defaults to infinite, and a node that
                 * accepts the handshake but never answers must fail into an error instead of hanging the request.
                 */
                'options' => $options + array_filter([
                        'username' => env('REDIS_USERNAME'),
                        'password' => env('REDIS_PASSWORD'),
                    ]) + [
                        'timeout' => env('REDIS_TIMEOUT', 2.0),
                        'read_timeout' => env('REDIS_READ_TIMEOUT', 2.0),
                    ],

                'clusters' => [
                    'default' => $clusterSeeds(),
                    'cache' => $clusterSeeds(),

                    /*
                     * Sessions share the cluster keyspace with everything else, so a client-level key
                     * prefix stands in for the database-index isolation of standalone mode;
                     * auth:flush-sessions deletes by prefix scan here instead of FLUSHDB.
                     */
                    'sessions' => [
                        ...$clusterSeeds(),
                        'options' => [
                            'prefix' => env('REDIS_SESSIONS_PREFIX', $appSlug.'-sessions-'),
                        ],
                    ],

                    /*
                     * The queue and Horizon (config/horizon.php `use`).
                     * Horizon hash-tags its own keys on a cluster, and the queue name gains a {hash tag} in config/queue.php.
                     */
                    'queue' => $clusterSeeds(),
                ],
            ],

            default => throw new RuntimeException(
                "Unsupported REDIS_TOPOLOGY [{$topology}] - use standalone, sentinel, or cluster."
            ),
        };
    })(),

];
