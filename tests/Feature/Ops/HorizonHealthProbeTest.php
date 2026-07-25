<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\HealthCheckService;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use Tests\TestCase;

class HorizonHealthProbeTest extends TestCase
{
    /**
     * Bind a repository reporting exactly the given master supervisors, so the
     * probe is exercised without a redis server.
     *
     * @param  list<object>  $masters
     */
    private function bindMasters(array $masters): void
    {
        $repository = Mockery::mock(MasterSupervisorRepository::class);
        $repository->shouldReceive('all')->andReturn($masters);

        $this->app->instance(MasterSupervisorRepository::class, $repository);
    }

    /**
     * The horizon probe's result from a full report, or null when it did not run.
     *
     * @return array{name: string, ok: bool, critical: bool, detail: string, duration_ms: float}|null
     */
    private function horizonProbe(): ?array
    {
        foreach (app(HealthCheckService::class)->report() as $probe) {
            if ($probe['name'] === 'horizon') {
                return $probe;
            }
        }

        return null;
    }

    public function test_the_probe_passes_while_a_master_supervisor_runs(): void
    {
        config(['queue.default' => 'redis']);
        $this->bindMasters([(object) ['name' => 'host-1', 'status' => 'running']]);

        $probe = $this->horizonProbe();

        $this->assertNotNull($probe);
        $this->assertTrue($probe['ok']);
        $this->assertFalse($probe['critical']);
        $this->assertSame('1 master supervisor(s) running', $probe['detail']);
    }

    public function test_the_probe_fails_when_horizon_is_not_running(): void
    {
        config(['queue.default' => 'redis']);
        $this->bindMasters([]);

        $probe = $this->horizonProbe();

        $this->assertNotNull($probe);
        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('horizon is not running', $probe['detail']);
    }

    public function test_the_probe_fails_when_every_master_is_paused(): void
    {
        config(['queue.default' => 'redis']);
        $this->bindMasters([
            (object) ['name' => 'host-1', 'status' => 'paused'],
            (object) ['name' => 'host-2', 'status' => 'paused'],
        ]);

        $probe = $this->horizonProbe();

        $this->assertNotNull($probe);
        $this->assertFalse($probe['ok']);
        $this->assertStringContainsString('horizon is paused', $probe['detail']);
    }

    public function test_one_running_master_keeps_the_probe_green_beside_a_paused_one(): void
    {
        config(['queue.default' => 'redis']);
        $this->bindMasters([
            (object) ['name' => 'host-1', 'status' => 'paused'],
            (object) ['name' => 'host-2', 'status' => 'running'],
        ]);

        $probe = $this->horizonProbe();

        $this->assertNotNull($probe);
        $this->assertTrue($probe['ok']);
    }

    public function test_the_probe_does_not_run_off_the_redis_queue(): void
    {
        // The suite's sync queue driver makes the probe inapplicable.
        $this->assertNull($this->horizonProbe());
    }

    public function test_the_probe_can_be_disabled(): void
    {
        config(['queue.default' => 'redis', 'health.probes.horizon' => false]);
        $this->bindMasters([]);

        $this->assertNull($this->horizonProbe());
    }
}
