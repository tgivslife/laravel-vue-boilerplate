<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\HealthCheckService;
use App\Support\Redis\SentinelInspector;
use Mockery;
use Tests\TestCase;

/**
 * The `sentinel` probe exists because nothing else notices lost redundancy: sessions and cache keep answering
 * off a process's cached master address while the whole fleet is down, so the first symptom would otherwise be
 * a failover that never happens. It is non-critical by design - redundancy is gone, serving is not.
 */
class SentinelHealthProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.redis.client' => 'phpredis-sentinel']);
    }

    /**
     * Bind an inspector reporting exactly the given fleet, so the probe runs without any sentinels.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function bindFleet(array $overrides = []): void
    {
        $inspector = Mockery::mock(SentinelInspector::class);
        $inspector->shouldReceive('inspect')->andReturn(array_merge([
            'configured' => 3,
            'answering' => 3,
            'quorum' => true,
            'masters' => ['10.0.0.1:6379' => 3],
            'replicas' => 1,
            'healthyReplicas' => 1,
            'failures' => [],
        ], $overrides));

        $this->app->instance(SentinelInspector::class, $inspector);
    }

    /**
     * The sentinel probe's result from a full report, or null when it did not run.
     *
     * @return array{name: string, ok: bool, critical: bool, detail: string, duration_ms: float}|null
     */
    private function sentinelProbe(): ?array
    {
        foreach (app(HealthCheckService::class)->report() as $probe) {
            if ($probe['name'] === 'sentinel') {
                return $probe;
            }
        }

        return null;
    }

    public function test_a_fleet_that_could_run_a_failover_passes(): void
    {
        $this->bindFleet();

        $probe = $this->sentinelProbe();

        $this->assertNotNull($probe);
        $this->assertTrue($probe['ok']);
        $this->assertFalse($probe['critical'], 'lost redundancy must not take the node out of the pool');
        $this->assertStringContainsString('3/3 sentinels answering', $probe['detail']);
        $this->assertStringContainsString('10.0.0.1:6379', $probe['detail']);
    }

    public function test_it_fails_when_no_sentinel_answers(): void
    {
        $this->bindFleet([
            'answering' => 0,
            'masters' => [],
            'failures' => ['10.0.0.1:26379 (Connection refused)', '10.0.0.2:26379 (Connection refused)'],
        ]);

        $probe = $this->sentinelProbe();

        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('no sentinel answered', $probe['detail']);
        $this->assertStringContainsString('10.0.0.2:26379', $probe['detail'], 'the detail must name what was tried');
    }

    public function test_it_fails_when_quorum_is_out_of_reach(): void
    {
        // Sentinels still answering and still naming a master, but too few to elect a leader.
        $this->bindFleet(['answering' => 1, 'quorum' => false]);

        $probe = $this->sentinelProbe();

        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('quorum is not reachable', $probe['detail']);
    }

    public function test_it_fails_when_the_sentinels_disagree_about_the_master(): void
    {
        $this->bindFleet(['masters' => ['10.0.0.1:6379' => 2, '10.0.0.2:6379' => 1]]);

        $probe = $this->sentinelProbe();

        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('disagree on the master', $probe['detail']);
        $this->assertStringContainsString('10.0.0.2:6379', $probe['detail']);
    }

    public function test_it_fails_when_there_is_nothing_to_promote(): void
    {
        // The single point of failure the data-path probes are structurally blind to: one master, no replica,
        // everything green right up until it dies.
        $this->bindFleet(['replicas' => 1, 'healthyReplicas' => 0]);

        $probe = $this->sentinelProbe();

        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('nothing to promote', $probe['detail']);
    }

    public function test_a_failing_fleet_never_gates_the_up_endpoint(): void
    {
        $this->bindFleet(['answering' => 0, 'masters' => [], 'failures' => ['10.0.0.1:26379 (Connection refused)']]);

        $names = array_column(app(HealthCheckService::class)->criticalFailures(), 'name');

        $this->assertNotContains('sentinel', $names);
    }

    public function test_the_probe_does_not_run_off_the_sentinel_topology(): void
    {
        config(['database.redis.client' => 'phpredis']);
        $this->bindFleet();

        $this->assertNull($this->sentinelProbe());
    }

    public function test_the_probe_can_be_disabled(): void
    {
        config(['health.probes.sentinel' => false]);
        $this->bindFleet();

        $this->assertNull($this->sentinelProbe());
    }
}
