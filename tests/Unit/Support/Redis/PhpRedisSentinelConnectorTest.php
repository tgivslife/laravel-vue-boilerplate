<?php

namespace Tests\Unit\Support\Redis;

use App\Support\Redis\PhpRedisSentinelConnection;
use App\Support\Redis\PhpRedisSentinelConnector;
use App\Support\Redis\SentinelDiscoveryException;
use RedisException;
use RuntimeException;
use Tests\TestCase;

/**
 * Master discovery as pure logic: the createSentinel() seam is overridden with scripted stubs,
 * so no test opens a socket. Each test uses a unique sentinel_service so the connector's
 * per-process master cache cannot leak state between tests.
 */
class PhpRedisSentinelConnectorTest extends TestCase
{
    /**
     * A connector whose sentinels are scripted: host:port => closure returning an address,
     * returning false, or throwing. Client creation is scripted too: hosts listed in
     * $deadMasters refuse to connect.
     *
     * @param  array<string, callable>  $sentinels
     */
    private function connectorWithSentinels(array $sentinels, array $deadMasters = [], array $replicaHosts = []): object
    {
        return new class($sentinels, $deadMasters, $replicaHosts) extends PhpRedisSentinelConnector
        {
            public int $discoveries = 0;

            /** @var list<string> Hosts createClient() was asked to connect to. */
            public array $clientHosts = [];

            public function __construct(
                private readonly array $sentinels,
                private array $deadMasters = [],
                private array $replicaHosts = [],
            ) {
            }

            protected function createClient(array $config): object
            {
                $this->clientHosts[] = "{$config['host']}:{$config['port']}";

                if (in_array($config['host'], $this->deadMasters, true)) {
                    throw new RedisException("read error on connection to {$config['host']}:{$config['port']}");
                }

                return new class(in_array($config['host'], $this->replicaHosts, true) ? 'slave' : 'master')
                {
                    public function __construct(private readonly string $role) {}

                    /** The connector verifies role:master on every rediscovery. */
                    public function info(string $section): array
                    {
                        return ['role' => $this->role];
                    }

                    public function close(): void
                    {
                    }
                };
            }

            public function exposeResolveMaster(array $config, bool $refresh = false): array
            {
                return $this->resolveMaster($config, $refresh);
            }

            public function exposeResolveMasterConfig(array $config, bool $refresh = false): array
            {
                return $this->resolveMasterConfig($config, $refresh);
            }

            public function exposeParseSentinelHosts(string|array $hosts): array
            {
                return $this->parseSentinelHosts($hosts);
            }

            public function exposeSentinelOptions(string $host, int $port, array $config): array
            {
                return $this->sentinelOptions($host, $port, $config);
            }

            protected function createSentinel(string $host, int $port, array $config): object
            {
                $this->discoveries++;

                $script = $this->sentinels["{$host}:{$port}"]
                    ?? static fn() => throw new RedisException('Connection refused');

                return new class($script)
                {
                    public function __construct(private $script)
                    {
                    }

                    public function getMasterAddrByName(string $service): array|false
                    {
                        return ($this->script)($service);
                    }
                };
            }
        };
    }

    /**
     * A per-test unique config, so the static master cache never collides across tests.
     */
    private function sentinelConfig(string $hosts): array
    {
        return [
            'sentinel_hosts' => $hosts,
            'sentinel_service' => 'svc-'.uniqid(),
        ];
    }

    public function test_parses_comma_separated_hosts_with_default_port(): void
    {
        $connector = $this->connectorWithSentinels([]);

        $this->assertSame(
            [['10.0.0.1', 26379], ['10.0.0.2', 26380], ['10.0.0.3', 26379]],
            $connector->exposeParseSentinelHosts(' 10.0.0.1 , 10.0.0.2:26380 ,, 10.0.0.3, ')
        );

        $this->assertSame([], $connector->exposeParseSentinelHosts(''));
        $this->assertSame([['redis-sentinel', 26379]], $connector->exposeParseSentinelHosts(['redis-sentinel']));
    }

    public function test_skips_failing_sentinels_and_uses_the_first_that_answers(): void
    {
        $connector = $this->connectorWithSentinels([
            'bad:26379' => static fn() => throw new RedisException('Connection refused'),
            'unaware:26379' => static fn() => false,
            'good:26379' => static fn() => ['10.0.0.9', '6380'],
        ]);

        $master = $connector->exposeResolveMaster($this->sentinelConfig('bad:26379,unaware:26379,good:26379'));

        $this->assertSame(['10.0.0.9', 6380], $master);
    }

    public function test_names_every_sentinel_tried_when_none_answers(): void
    {
        $connector = $this->connectorWithSentinels([
            'down-a:26379' => static fn() => throw new RedisException('Connection refused'),
            'down-b:26380' => static fn() => false,
            // An empty host answer must count as "no master known", never reach createClient.
            'weird:26381' => static fn() => ['', '6380'],
        ]);

        try {
            $connector->exposeResolveMaster($this->sentinelConfig('down-a:26379,down-b:26380,weird:26381'));
            $this->fail('Expected a SentinelDiscoveryException naming every sentinel tried.');
        } catch (SentinelDiscoveryException $exception) {
            $this->assertStringContainsString('down-a:26379 (Connection refused)', $exception->getMessage());
            $this->assertStringContainsString('down-b:26380 (no usable master known', $exception->getMessage());
            $this->assertStringContainsString('weird:26381 (no usable master known', $exception->getMessage());
        }
    }

    public function test_rejects_an_empty_sentinel_host_list(): void
    {
        $connector = $this->connectorWithSentinels([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_SENTINEL_HOSTS');

        $connector->exposeResolveMaster($this->sentinelConfig(''));
    }

    public function test_caches_the_master_per_process_and_refresh_bypasses_the_cache(): void
    {
        $connector = $this->connectorWithSentinels([
            's1:26379' => static fn() => ['10.0.0.9', '6380'],
        ]);

        $config = $this->sentinelConfig('s1:26379');

        $connector->exposeResolveMaster($config);
        $connector->exposeResolveMaster($config);

        $this->assertSame(1, $connector->discoveries, 'the second resolution must hit the cache');

        $connector->exposeResolveMaster($config, refresh: true);

        $this->assertSame(2, $connector->discoveries, 'refresh must force a fresh discovery');
    }

    public function test_rewrites_host_and_port_and_strips_discovery_keys(): void
    {
        $connector = $this->connectorWithSentinels([
            's1:26379' => static fn() => ['10.0.0.9', '6380'],
        ]);

        $config = $this->sentinelConfig('s1:26379') + [
            'url' => 'redis://stale-host:9999',
            'host' => 'placeholder',
            'port' => '6379',
            'password' => 'secret',
            'database' => '2',
            'max_retries' => 3,
            'sentinel_username' => 'ops',
            'sentinel_password' => 'sentinel-secret',
            'sentinel_timeout' => 0.25,
            'retry_attempts' => 5,
            'retry_delay' => 100,
            'retry_deadline' => 4000,
        ];

        $resolved = $connector->exposeResolveMasterConfig($config);

        $this->assertSame('10.0.0.9', $resolved['host']);
        $this->assertSame(6380, $resolved['port']);

        /*
         * Every discovery key, so adding one to SENTINEL_CONFIG_KEYS without adding it here is the only way to
         * leave a leak unnoticed. `url` is in the list as belt-and-braces rather than as protection against
         * ConfigurationUrlParser: RedisManager::parseConnectionConfiguration() runs the parser first and pulls
         * `url` out before any connector sees the config, so on the RedisManager path the key never arrives.
         * It stays covered because resolveMasterConfig() is reachable directly, as it is right here.
         */
        foreach ([
            'url',
            'sentinel_hosts',
            'sentinel_service',
            'sentinel_username',
            'sentinel_password',
            'sentinel_timeout',
            'retry_attempts',
            'retry_delay',
            'retry_deadline',
        ] as $stripped) {
            $this->assertArrayNotHasKey($stripped, $resolved, "[{$stripped}] must not reach createClient()");
        }

        $this->assertSame('secret', $resolved['password'], 'the data-node credentials are not discovery keys');
        $this->assertSame('2', $resolved['database']);
        $this->assertSame(3, $resolved['max_retries']);
    }

    public function test_sentinel_auth_shapes_follow_redis_acl_semantics(): void
    {
        $connector = $this->connectorWithSentinels([]);

        $both = $connector->exposeSentinelOptions('s1', 26379, [
            'sentinel_username' => 'ops', 'sentinel_password' => 'secret',
        ]);
        $passwordOnly = $connector->exposeSentinelOptions('s1', 26379, ['sentinel_password' => 'secret']);
        $anonymous = $connector->exposeSentinelOptions('s1', 26379, ['sentinel_timeout' => 0.25]);

        $this->assertSame(['ops', 'secret'], $both['auth']);
        $this->assertSame('secret', $passwordOnly['auth']);
        $this->assertArrayNotHasKey('auth', $anonymous);
        $this->assertSame(0.25, $anonymous['connectTimeout']);
        $this->assertSame(0.25, $anonymous['readTimeout']);
    }

    public function test_connect_falls_back_to_fresh_discovery_when_the_cached_master_is_dead(): void
    {
        $service = 'svc-'.uniqid();

        $config = [
            'sentinel_hosts' => 's1:26379',
            'sentinel_service' => $service,
            'host' => 'placeholder',
            'port' => '6379',
            'retry_delay' => 0,
        ];

        // Stage 1: warm the (class-static, instance-independent) cache with the old master,
        // exactly as a pre-failover connect would have.
        $this->connectorWithSentinels(['s1:26379' => static fn() => ['10.0.0.9', '6380']])
            ->exposeResolveMaster($config);

        // Stage 2: 10.0.0.9 dies, sentinel now reports the promoted node.
        $connector = $this->connectorWithSentinels(
            ['s1:26379' => static fn() => ['10.0.0.2', '6381']],
            deadMasters: ['10.0.0.9'],
        );

        $connection = $connector->connect($config, []);

        // First attempt used the stale cache (dead), the fallback rediscovered and connected.
        $this->assertSame(['10.0.0.9:6380', '10.0.0.2:6381'], $connector->clientHosts);
        $this->assertInstanceOf(\App\Support\Redis\PhpRedisSentinelConnection::class, $connection);
    }

    public function test_connect_retries_until_the_election_names_a_master(): void
    {
        /*
         * The php-fpm case: no connection exists yet, so the in-command retry loop can never see this. The
         * sentinels are up and answering but know no master for the first two attempts, exactly as they do
         * between a master dying and a replica being promoted.
         */
        $answers = [false, false, ['10.0.0.5', '6379']];

        $connector = $this->connectorWithSentinels([
            's1:26379' => static function () use (&$answers) {
                return array_shift($answers);
            },
        ]);

        $connection = $connector->connect($this->sentinelConfig('s1:26379') + [
            'host' => 'placeholder', 'port' => '6379', 'retry_delay' => 0,
        ], []);

        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $connection);
        $this->assertSame(['10.0.0.5:6379'], $connector->clientHosts, 'only the promoted node is ever connected to');
        $this->assertSame(3, $connector->discoveries);
    }

    public function test_connect_fails_fast_when_no_sentinel_answers_at_all(): void
    {
        // An unreachable fleet does not become reachable by waiting, so the budget must not be spent on it -
        // the error has to reach the health probes promptly, naming what was tried.
        $connector = $this->connectorWithSentinels([]);

        try {
            $connector->connect($this->sentinelConfig('down-a:26379,down-b:26379') + [
                'host' => 'placeholder', 'port' => '6379', 'retry_delay' => 0,
            ], []);

            $this->fail('Expected discovery to fail fast.');
        } catch (SentinelDiscoveryException $exception) {
            $this->assertFalse($exception->anySentinelAnswered);
            $this->assertSame(2, $connector->discoveries, 'one sweep of the fleet, no retries');
            $this->assertStringContainsString('down-b:26379', $exception->getMessage());
        }
    }

    public function test_a_rediscovered_node_that_is_still_a_replica_is_rejected_and_retried(): void
    {
        /*
         * Sentinels flip their view before the old master finishes demoting. A replica answers reads happily,
         * so without the role check the mistake would stay invisible until the next write.
         */
        // The dead node first, so the loop is already in refresh territory when the sentinels hand back the
        // not-yet-demoted replica - the role check only runs on rediscovery, never on the happy path.
        $addresses = [['10.0.0.7', '6379'], ['10.0.0.9', '6379'], ['10.0.0.2', '6379']];

        $connector = $this->connectorWithSentinels(
            ['s1:26379' => static function () use (&$addresses) {
                return array_shift($addresses);
            }],
            deadMasters: ['10.0.0.7'],
            replicaHosts: ['10.0.0.9'],
        );

        $connection = $connector->connect($this->sentinelConfig('s1:26379') + [
            'host' => 'placeholder', 'port' => '6379', 'retry_delay' => 0,
        ], []);

        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $connection);
        $this->assertSame(
            ['10.0.0.7:6379', '10.0.0.9:6379', '10.0.0.2:6379'],
            $connector->clientHosts,
            'the replica must be rejected and rediscovery tried again, not accepted as the master',
        );
    }

    public function test_the_role_check_stays_off_the_happy_path(): void
    {
        // One INFO per rediscovery is cheap; one per connection would be four per request for nothing.
        $connector = $this->connectorWithSentinels(
            ['s1:26379' => static fn() => ['10.0.0.9', '6379']],
            replicaHosts: ['10.0.0.9'],
        );

        $connection = $connector->connect($this->sentinelConfig('s1:26379') + [
            'host' => 'placeholder', 'port' => '6379', 'retry_delay' => 0,
        ], []);

        $this->assertInstanceOf(PhpRedisSentinelConnection::class, $connection);
        $this->assertSame(['10.0.0.9:6379'], $connector->clientHosts);
    }

    public function test_cluster_connections_are_refused_with_a_named_error(): void
    {
        $connector = $this->connectorWithSentinels([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_TOPOLOGY=cluster');

        $connector->connectToCluster([], [], []);
    }
}
