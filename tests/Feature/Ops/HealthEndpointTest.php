<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\HealthCheckService;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_up_responds_ok_while_the_critical_probes_pass(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_up_responds_500_when_a_critical_probe_fails(): void
    {
        // The service is readonly (Mockery cannot subclass it), so the stub extends it for real.
        $this->app->instance(HealthCheckService::class, new readonly class extends HealthCheckService {
            public function criticalFailures(): array
            {
                return [[
                    'name' => 'database',
                    'ok' => false,
                    'critical' => true,
                    'detail' => 'connection refused',
                    'duration_ms' => 1.0,
                ]];
            }
        });

        $this->withExceptionHandling()->get('/up')->assertStatus(500);
    }
}
