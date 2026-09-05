<?php

namespace App\Support\Redis;

use RedisSentinel;
use RuntimeException;

/**
 * Builds RedisSentinel clients from a `database.redis.*` connection configuration.
 *
 * Two things talk to the sentinels for different reasons - {@see PhpRedisSentinelConnector} to discover the master on the request path,
 * and {@see SentinelInspector} to report fleet health - and they must agree on host parsing, ACL semantics and the
 * probe timeout down to the last detail, or the health probe ends up describing a fleet the connector is not actually using.
 * Host parsing itself lives in {@see HostListParser}, shared with the cluster seed list.
 */
class SentinelClientFactory
{
    /**
     * The port a host entry gets when it does not name one.
     */
    private const int DEFAULT_SENTINEL_PORT = 26379;

    public function __construct(
        private readonly HostListParser $hosts = new HostListParser('REDIS_SENTINEL_HOSTS',
            self::DEFAULT_SENTINEL_PORT),
    ) {
    }

    /**
     * Open a connection to a single sentinel.
     *
     * @return object A RedisSentinel (or, in tests, a stand-in) answering getMasterAddrByName().
     */
    public function make(string $host, int $port, array $config): object
    {
        return new RedisSentinel($this->options($host, $port, $config));
    }

    /**
     * Parse the comma-separated sentinel host list into [host, port] pairs.
     *
     * @param  string|array  $hosts
     * @return list<array{0: string, 1: int}>
     */
    public function parseHosts(string|array $hosts): array
    {
        return $this->hosts->parseHosts($hosts);
    }

    /**
     * The RedisSentinel constructor options for one sentinel host.
     *
     * Sentinel auth follows Redis 6.2+ semantics: an ACL [user, password] pair when both are set, the bare requirepass
     * password when only that is, no auth key otherwise.
     * A username without a password is refused rather than quietly downgraded - it authenticates nothing, and silently
     * talking to the sentinels anonymously is the kind of thing that only surfaces when an ACL finally starts being enforced.
     *
     * @return array{host: string, port: int, connectTimeout: float, readTimeout: float, auth?: string|array{0: string, 1: string}}
     *
     * @throws RuntimeException When only one half of the sentinel credentials is configured.
     */
    public function options(string $host, int $port, array $config): array
    {
        $timeout = (float) ($config['sentinel_timeout'] ?? 0.5);

        $options = [
            'host' => $host,
            'port' => $port,
            'connectTimeout' => $timeout,
            'readTimeout' => $timeout,
        ];

        $username = trim((string) ($config['sentinel_username'] ?? ''));
        $password = trim((string) ($config['sentinel_password'] ?? ''));

        if ($username !== '' && $password === '') {
            throw new RuntimeException(
                'REDIS_SENTINEL_USERNAME is set without REDIS_SENTINEL_PASSWORD - set both, or neither.'
            );
        }

        if ($username !== '') {
            $options['auth'] = [$username, $password];
        } elseif ($password !== '') {
            $options['auth'] = $password;
        }

        return $options;
    }
}
