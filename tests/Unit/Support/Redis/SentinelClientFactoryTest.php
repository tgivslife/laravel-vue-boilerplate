<?php

namespace Tests\Unit\Support\Redis;

use App\Support\Redis\SentinelClientFactory;
use RuntimeException;
use Tests\TestCase;

/**
 * Host parsing and sentinel auth, shared by the connector and the health probe.
 *
 * Both have to read REDIS_SENTINEL_HOSTS identically or the probe ends up reporting on a fleet the connector
 * is not talking to, so this is the one definition and the one place the edge cases are pinned.
 */
class SentinelClientFactoryTest extends TestCase
{
    private SentinelClientFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SentinelClientFactory;
    }

    public function test_it_parses_comma_separated_hosts_with_a_default_port(): void
    {
        $this->assertSame(
            [['10.0.0.1', 26379], ['10.0.0.2', 26380], ['10.0.0.3', 26379]],
            $this->factory->parseHosts(' 10.0.0.1 , 10.0.0.2:26380 ,, 10.0.0.3, ')
        );

        $this->assertSame([], $this->factory->parseHosts(''));
        $this->assertSame([['redis-sentinel', 26379]], $this->factory->parseHosts(['redis-sentinel']));
    }

    public function test_it_reads_ipv6_literals_bracketed_and_bare(): void
    {
        /*
         * Splitting on the last colon would read a bare literal as host `fd00:` on port 1. The brackets come
         * back off because phpredis re-adds them itself the moment it sees a colon in the host.
         */
        $this->assertSame(
            [['fd00::1', 26379], ['fd00::1', 26380], ['::1', 26379], ['::1', 26379]],
            $this->factory->parseHosts('[fd00::1], [fd00::1]:26380, ::1, [::1]')
        );
    }

    public function test_it_refuses_a_port_it_cannot_use_instead_of_defaulting(): void
    {
        // Silently falling back to 26379 turns "wrong port" into "mysteriously unreachable sentinel" later.
        foreach (['host:0', 'host:abc', 'host:99999', 'host:-1'] as $entry) {
            try {
                $this->factory->parseHosts($entry);
                $this->fail("Expected [{$entry}] to be refused.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Invalid port', $exception->getMessage());
                $this->assertStringContainsString('REDIS_SENTINEL_HOSTS', $exception->getMessage(),
                    'the error must name the setting to fix');
                $this->assertStringContainsString($entry, $exception->getMessage());
            }
        }
    }

    public function test_it_refuses_an_unclosed_ipv6_bracket(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('closing bracket');

        $this->factory->parseHosts('[fd00::1');
    }

    public function test_sentinel_auth_shapes_follow_redis_acl_semantics(): void
    {
        $both = $this->factory->options('s1', 26379, [
            'sentinel_username' => 'ops', 'sentinel_password' => 'secret',
        ]);
        $passwordOnly = $this->factory->options('s1', 26379, ['sentinel_password' => 'secret']);
        $anonymous = $this->factory->options('s1', 26379, ['sentinel_timeout' => 0.25]);

        $this->assertSame(['ops', 'secret'], $both['auth']);
        $this->assertSame('secret', $passwordOnly['auth']);
        $this->assertArrayNotHasKey('auth', $anonymous);
        $this->assertSame(0.25, $anonymous['connectTimeout']);
        $this->assertSame(0.25, $anonymous['readTimeout']);
    }

    public function test_half_configured_credentials_fail_loudly(): void
    {
        // A username alone authenticates nothing; contacting the sentinels anonymously instead would only
        // surface the day an ACL starts being enforced.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_SENTINEL_USERNAME is set without REDIS_SENTINEL_PASSWORD');

        $this->factory->options('s1', 26379, ['sentinel_username' => 'ops']);
    }
}
