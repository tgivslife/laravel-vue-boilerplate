<?php

namespace App\Support\Redis;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use RedisException;
use RuntimeException;

/**
 * phpredis connector for the Sentinel topology (REDIS_TOPOLOGY=sentinel).
 *
 * Laravel's phpredis driver has no Sentinel support, so this connector asks the configured sentinels which node is
 * currently the master (RedisSentinel::getMasterAddrByName) and then opens a completely ordinary phpredis connection
 * to it - auth, SELECT of the per-connection database index, retry/backoff options and key prefix are all inherited
 * from the stock connector, which is why every consumer (sessions, cache, queue, Horizon, auth:flush-sessions)
 * behaves exactly as in standalone mode.
 *
 * Discovery runs through a per-process address cache keyed by sentinel service, so the four connections of one request
 * pay for at most one sentinel round-trip.
 * The client-factory closure handed to the connection accepts a `$refresh` flag: the failover path in
 * {@see PhpRedisSentinelConnection} passes true to bypass the cache and re-resolve the master.
 *
 * Opening the connection is retried on the same budget as any command ({@see SentinelRetryPolicy}), because under
 * php-fpm that IS the common case: statics do not survive between requests, so every request rediscovers and
 * reconnects from scratch and never reaches the in-command loop at all. Only a fleet where no sentinel answers is
 * fail-fast - retrying an unreachable fleet buys nothing, and the error names every host tried so the health probes
 * can say why.
 *
 * Requires phpredis >= 6.0 (the RedisSentinel options-array constructor).
 */
class PhpRedisSentinelConnector extends PhpRedisConnector
{
    /**
     * Configuration keys consumed by discovery, stripped before the client is created.
     *
     * `url` is belt-and-braces rather than load-bearing: RedisManager::parseConnectionConfiguration() runs
     * ConfigurationUrlParser first, which pulls `url` out and folds it into host/port before a connector ever
     * sees the config - so by the time discovery overwrites host/port there is nothing left to reinstate. It
     * stays listed so the connector remains correct if it is ever driven directly.
     *
     * @var list<string>
     */
    private const array SENTINEL_CONFIG_KEYS = [
        'url',
        'sentinel_hosts',
        'sentinel_service',
        'sentinel_username',
        'sentinel_password',
        'sentinel_timeout',
        'retry_attempts',
        'retry_delay',
        'retry_deadline',
    ];

    /**
     * Per-process master address cache: service cache key => [host, port].
     *
     * A stale entry costs exactly one retryable command failure before the refresh path
     * (see PhpRedisSentinelConnection) re-resolves; no cross-process cache by design.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private static array $resolvedMasters = [];

    /**
     * The shared sentinel-client builder, created on first use.
     */
    private ?SentinelClientFactory $sentinelClients = null;

    /**
     * Create a new connection to the current Sentinel master.
     *
     * Mirrors the stock connector, except the client factory re-resolves the master on every invocation with
     * `$refresh = true` - the stock closure pins the host it captured, which after a failover is the demoted node.
     *
     * @param  array  $config  The connection configuration (a `database.redis.*` entry).
     * @param  array  $options  The `database.redis.options` array.
     */
    public function connect(array $config, array $options): PhpRedisSentinelConnection
    {
        $formattedOptions = Arr::pull($config, 'options', []);

        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }

        $connector = function (bool $refresh = false) use ($config, $options, $formattedOptions) {
            $master = $this->resolveMasterConfig($config, $refresh);

            $client = $this->createClient(array_merge($master, $options, $formattedOptions));

            if ($refresh) {
                $this->assertMaster($client, $master);
            }

            return $client;
        };

        $policy = SentinelRetryPolicy::fromConfig($config);

        /*
         * The first attempt may use the cached address (one sentinel round-trip per request, not four); every
         * retry forces rediscovery, so a cached address that has gone stale costs exactly one attempt.
         */
        $refresh = false;

        $client = $policy->run(
            function () use ($connector, &$refresh) {
                return $connector($refresh);
            },
            function () use (&$refresh): void {
                $refresh = true;
            },
            'connect',
        );

        return new PhpRedisSentinelConnection(
            $client,
            $connector,
            Arr::except($config, self::SENTINEL_CONFIG_KEYS),
            $policy,
        );
    }

    /**
     * Reject a freshly discovered node that is not actually the master.
     *
     * Only ever called on rediscovery, which keeps the happy path at zero extra round-trips: sentinels flip their
     * view before the old master finishes demoting, and a replica answers reads perfectly well, so without this the
     * mistake would stay invisible until the next write. One INFO on the slow path closes that window.
     *
     * @param  object  $client  The freshly connected client.
     * @param  array  $master  The resolved configuration, for naming the node in the error.
     *
     * @throws SentinelDiscoveryException Retryable - the promotion simply has not landed yet.
     */
    protected function assertMaster(object $client, array $master): void
    {
        $node = "{$master['host']}:{$master['port']}";

        try {
            $role = $client->info('replication')['role'] ?? null;
        } catch (RedisException $exception) {
            throw new SentinelDiscoveryException(
                "Could not verify the role of the discovered Redis master [{$node}]: {$exception->getMessage()}",
                anySentinelAnswered: true,
            );
        }

        if ($role !== 'master') {
            throw new SentinelDiscoveryException(
                "The node the sentinels named as master [{$node}] reports role:".($role ?? 'unknown')
                .' - the promotion has not landed yet.',
                anySentinelAnswered: true,
            );
        }
    }

    /**
     * Sentinel manages a master/replica pair, never a cluster - fail with a named error instead of something cryptic
     * if the topologies are ever mixed up.
     *
     * @param  array  $config
     * @param  array  $clusterOptions
     * @param  array  $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): never
    {
        throw new RuntimeException(
            'The phpredis-sentinel driver does not support cluster connections - use REDIS_TOPOLOGY=cluster instead.'
        );
    }

    /**
     * Rewrite the connection configuration to point at the current master.
     *
     * @param  array  $config
     * @param  bool  $refresh
     * @return array The configuration with `host`/`port` replaced and every discovery key removed.
     */
    protected function resolveMasterConfig(array $config, bool $refresh = false): array
    {
        [$host, $port] = $this->resolveMaster($config, $refresh);

        return array_merge(Arr::except($config, self::SENTINEL_CONFIG_KEYS), [
            'host' => $host,
            'port' => $port,
        ]);
    }

    /**
     * Ask the sentinels for the current master address, trying each configured host in order.
     *
     * Unreachable or unaware sentinels are collected and skipped; when none answers, the exception names every host
     * tried and why, so a dead sentinel fleet is diagnosable from one log line. It also records whether *any* of
     * them responded, which is what decides retryable-election from fail-fast-outage - see
     * {@see SentinelDiscoveryException}.
     *
     * @param  array  $config
     * @param  bool  $refresh
     * @return array{0: string, 1: int} The master host and port.
     *
     */
    protected function resolveMaster(array $config, bool $refresh = false): array
    {
        $service = (string) ($config['sentinel_service'] ?? 'mymaster');
        $hosts = $this->parseSentinelHosts($config['sentinel_hosts'] ?? '');
        $cacheKey = $service.'|'.implode(',',
                array_map(static fn(array $host): string => "{$host[0]}:{$host[1]}", $hosts));

        if (!$refresh && isset(self::$resolvedMasters[$cacheKey])) {
            return self::$resolvedMasters[$cacheKey];
        }

        if ($hosts === []) {
            throw new RuntimeException('No Redis sentinel hosts configured - set REDIS_SENTINEL_HOSTS.');
        }

        $failures = [];
        $answered = false;

        foreach ($hosts as [$host, $port]) {
            try {
                $address = $this->createSentinel($host, $port, $config)->getMasterAddrByName($service);
            } catch (RedisException $exception) {
                $failures[] = "{$host}:{$port} ({$exception->getMessage()})";

                continue;
            }

            $answered = true;

            // An empty host would only blow up later inside createClient (formatHost rejects it), and a port
            // outside the legal range the same way; treat either like a sentinel that knows no master and keep
            // iterating.
            if (!is_array($address) || count($address) < 2 || (string) $address[0] === ''
                || (int) $address[1] < 1 || (int) $address[1] > 65535) {
                $failures[] = "{$host}:{$port} (no usable master known for service [{$service}])";

                continue;
            }

            return self::$resolvedMasters[$cacheKey] = [(string) $address[0], (int) $address[1]];
        }

        throw new SentinelDiscoveryException(sprintf(
            'Unable to resolve the Redis master for service [%s] from any configured sentinel: %s',
            $service,
            implode('; ', $failures),
        ), anySentinelAnswered: $answered);
    }

    /**
     * Parse the comma-separated sentinel host list into [host, port] pairs.
     *
     * @param  string|array  $hosts
     * @return list<array{0: string, 1: int}>
     */
    protected function parseSentinelHosts(string|array $hosts): array
    {
        return $this->sentinelClients()->parseHosts($hosts);
    }

    /**
     * Open a connection to a single sentinel.
     *
     * Protected as the test seam: unit tests substitute a stub here rather than opening a socket.
     *
     * @return object A RedisSentinel (or stand-in) answering getMasterAddrByName().
     */
    protected function createSentinel(string $host, int $port, array $config): object
    {
        return $this->sentinelClients()->make($host, $port, $config);
    }

    /**
     * The RedisSentinel constructor options for one sentinel host.
     *
     * @return array{host: string, port: int, connectTimeout: float, readTimeout: float, auth?: string|array{0: string, 1: string}}
     */
    protected function sentinelOptions(string $host, int $port, array $config): array
    {
        return $this->sentinelClients()->options($host, $port, $config);
    }

    /**
     * The shared sentinel-client builder.
     *
     * Resolved lazily rather than injected through the constructor: RedisManager instantiates connectors with no
     * arguments, and subclasses in tests routinely replace the constructor outright, so a promoted property
     * would be left uninitialised.
     */
    protected function sentinelClients(): SentinelClientFactory
    {
        return $this->sentinelClients ??= new SentinelClientFactory;
    }
}
