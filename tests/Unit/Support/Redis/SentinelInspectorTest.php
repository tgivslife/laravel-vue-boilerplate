<?php

namespace Tests\Unit\Support\Redis;

use App\Support\Redis\SentinelInspector;
use RedisException;
use Tests\TestCase;

/**
 * The fleet report behind the `sentinel` health probe, with scripted sentinels and no sockets.
 *
 * The interesting cases are the ones the data-path probes cannot see at all: sessions and cache keep passing
 * off cached discovery while redundancy is gone, so everything asserted here is something that would
 * otherwise stay invisible until a failover was needed and did not happen.
 */
class SentinelInspectorTest extends TestCase
{
    /**
     * @param  array<string, array{master?: array|false, quorum?: bool, slaves?: list<array>}>  $sentinels
     */
    private function inspector(array $sentinels): SentinelInspector
    {
        return new class($sentinels) extends SentinelInspector
        {
            public function __construct(private readonly array $scripted)
            {
                parent::__construct();
            }

            protected function createSentinel(string $host, int $port, array $config): object
            {
                $script = $this->scripted["{$host}:{$port}"] ?? null;

                if ($script === null) {
                    throw new RedisException('Connection refused');
                }

                return new class($script)
                {
                    public function __construct(private readonly array $script) {}

                    public function getMasterAddrByName(string $service): array|false
                    {
                        return $this->script['master'] ?? ['127.0.0.1', '6380'];
                    }

                    public function ckquorum(string $service): bool
                    {
                        return $this->script['quorum'] ?? true;
                    }

                    public function slaves(string $service): array
                    {
                        return $this->script['slaves'] ?? [self::healthyReplica()];
                    }

                    public static function healthyReplica(): array
                    {
                        return ['flags' => 'slave', 'master-link-status' => 'ok'];
                    }
                };
            }
        };
    }

    private function config(string $hosts): array
    {
        return ['sentinel_hosts' => $hosts, 'sentinel_service' => 'mymaster'];
    }

    public function test_a_healthy_fleet_agrees_on_one_master_and_has_something_to_promote(): void
    {
        $fleet = $this->inspector([
            's1:26379' => [], 's2:26379' => [], 's3:26379' => [],
        ])->inspect($this->config('s1,s2,s3'));

        $this->assertSame(3, $fleet['configured']);
        $this->assertSame(3, $fleet['answering']);
        $this->assertTrue($fleet['quorum']);
        $this->assertSame(['127.0.0.1:6380' => 3], $fleet['masters']);
        $this->assertSame(1, $fleet['healthyReplicas']);
        $this->assertSame([], $fleet['failures']);
    }

    public function test_unreachable_sentinels_are_counted_and_named(): void
    {
        $fleet = $this->inspector(['s1:26379' => []])->inspect($this->config('s1,s2,s3'));

        $this->assertSame(3, $fleet['configured']);
        $this->assertSame(1, $fleet['answering'], 'redundancy is gone even though serving is unaffected');
        $this->assertCount(2, $fleet['failures']);
        $this->assertStringContainsString('s2:26379', implode(' ', $fleet['failures']));
    }

    public function test_replica_counts_are_the_best_single_view_not_a_sum(): void
    {
        // Every sentinel monitors the same pair, so summing would report three replicas where there is one.
        $fleet = $this->inspector([
            's1:26379' => [], 's2:26379' => [], 's3:26379' => [],
        ])->inspect($this->config('s1,s2,s3'));

        $this->assertSame(1, $fleet['replicas']);
        $this->assertSame(1, $fleet['healthyReplicas']);
    }

    public function test_a_replica_that_could_not_be_promoted_is_not_counted_as_healthy(): void
    {
        $fleet = $this->inspector([
            's1:26379' => ['slaves' => [
                ['flags' => 's_down,slave', 'master-link-status' => 'ok'],
                ['flags' => 'slave', 'master-link-status' => 'err'],
            ]],
        ])->inspect($this->config('s1'));

        $this->assertSame(2, $fleet['replicas']);
        $this->assertSame(0, $fleet['healthyReplicas'], 'a down or desynced replica is nothing to promote');
    }

    public function test_disagreement_about_the_master_is_reported_per_address(): void
    {
        $fleet = $this->inspector([
            's1:26379' => ['master' => ['127.0.0.1', '6380']],
            's2:26379' => ['master' => ['127.0.0.1', '6380']],
            's3:26379' => ['master' => ['127.0.0.1', '6381']],
        ])->inspect($this->config('s1,s2,s3'));

        $this->assertSame(['127.0.0.1:6380' => 2, '127.0.0.1:6381' => 1], $fleet['masters']);
    }

    public function test_quorum_is_false_only_when_no_sentinel_claims_it(): void
    {
        $lost = $this->inspector([
            's1:26379' => ['quorum' => false],
            's2:26379' => ['quorum' => false],
        ])->inspect($this->config('s1,s2'));

        $partial = $this->inspector([
            's1:26379' => ['quorum' => false],
            's2:26379' => ['quorum' => true],
        ])->inspect($this->config('s1,s2'));

        $this->assertFalse($lost['quorum']);
        $this->assertTrue($partial['quorum']);
    }

    public function test_a_sentinel_naming_no_master_still_counts_as_answering(): void
    {
        // Mid-election the fleet is up and talking; it just has nothing to name yet.
        $fleet = $this->inspector(['s1:26379' => ['master' => false]])->inspect($this->config('s1'));

        $this->assertSame(1, $fleet['answering']);
        $this->assertSame([], $fleet['masters']);
    }
}
