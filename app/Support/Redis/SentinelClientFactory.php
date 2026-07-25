<?php

namespace App\Support\Redis;

use RedisSentinel;
use RuntimeException;

/**
 * Builds RedisSentinel clients from a `database.redis.*` connection configuration.
 *
 * Two things talk to the sentinels for different reasons - {@see PhpRedisSentinelConnector} to discover the
 * master on the request path, and {@see SentinelInspector} to report fleet health - and they must agree on
 * host parsing, ACL semantics and the probe timeout down to the last detail, or the health probe ends up
 * describing a fleet the connector is not actually using.
 */
class SentinelClientFactory
{
    /**
     * The port a host entry gets when it does not name one.
     */
    private const int DEFAULT_SENTINEL_PORT = 26379;

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
        $segments = is_array($hosts) ? $hosts : explode(',', $hosts);

        $parsed = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);

            if ($segment === '') {
                continue;
            }

            $parsed[] = $this->parseHost($segment);
        }

        return $parsed;
    }

    /**
     * The RedisSentinel constructor options for one sentinel host.
     *
     * Sentinel auth follows Redis 6.2+ semantics: an ACL [user, password] pair when both are set, the bare
     * requirepass password when only that is, no auth key otherwise. A username without a password is refused
     * rather than quietly downgraded - it authenticates nothing, and silently talking to the sentinels
     * anonymously is the kind of thing that only surfaces when an ACL finally starts being enforced.
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

    /**
     * Split one `host`, `host:port`, `[v6]` or `[v6]:port` entry.
     *
     * A bare IPv6 literal is nothing but colons, so splitting on the last one would read `fd00::1` as host
     * `fd00:` on port 1 and then fail somewhere far away from the typo. Brackets are the disambiguator
     * RFC 3986 exists for; unbracketed, anything holding more than one colon is taken as a bare address on the
     * default port, which is the only reading that cannot silently connect somewhere unintended. The brackets
     * are then stripped, because phpredis re-adds them itself when it sees a colon in the host.
     *
     * @return array{0: string, 1: int}
     */
    protected function parseHost(string $segment): array
    {
        if (str_starts_with($segment, '[')) {
            $close = strpos($segment, ']');

            if ($close === false) {
                throw new RuntimeException(
                    "Malformed sentinel host [{$segment}] in REDIS_SENTINEL_HOSTS - a bracketed IPv6 literal needs its closing bracket."
                );
            }

            return [
                substr($segment, 1, $close - 1),
                $this->parsePort(ltrim(substr($segment, $close + 1), ':'), $segment),
            ];
        }

        $colon = strrpos($segment, ':');

        if ($colon === false || $colon !== strpos($segment, ':')) {
            return [$segment, self::DEFAULT_SENTINEL_PORT];
        }

        return [substr($segment, 0, $colon), $this->parsePort(substr($segment, $colon + 1), $segment)];
    }

    /**
     * Validate the port half of a host entry.
     *
     * A typo used to fall back to 26379 silently, which turns "wrong port" into "mysteriously unreachable
     * sentinel" much later. It now fails at first discovery instead - the host list is parsed when a
     * connection is opened, not when config is loaded, so this is not caught by `config:cache`.
     *
     * @throws RuntimeException When a port is present but is not a legal TCP port.
     */
    protected function parsePort(string $port, string $segment): int
    {
        if ($port === '') {
            return self::DEFAULT_SENTINEL_PORT;
        }

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException("Invalid sentinel port [{$port}] in REDIS_SENTINEL_HOSTS entry [{$segment}].");
        }

        return (int) $port;
    }
}
