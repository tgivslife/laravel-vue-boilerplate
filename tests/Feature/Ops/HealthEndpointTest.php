<?php

namespace Tests\Feature\Ops;

use App\Services\Ops\HealthCheckService;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_up_responds_ok_while_the_critical_probes_pass(): void
    {
        $this->get('/up')->assertOk()->assertSee('Application up')->assertDontSee('maintenance')
            ->assertSeeInOrder(['Response rendered in ', 'ms.']);

        $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up', 'maintenance' => false]);
    }

    public function test_up_responds_500_when_a_critical_probe_fails(): void
    {
        // The provoked failure is reported by design; faking the handler keeps that expected entry
        // out of laravel.log and lets the report itself be asserted.
        Exceptions::fake();
        $this->failDatabaseProbe();

        $response = $this->get('/up')->assertStatus(500)->assertSee('experiencing problems');

        // The page names the probe for whoever opens it, and nothing more: the URL is unauthenticated
        // and the detail may carry a host or port.
        $response->assertSee('database');
        $response->assertDontSee('connection refused');

        $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down', 'maintenance' => false]);

        // The 500 alone would pass even if something unrelated threw: what the load balancer's
        // operator needs is the failing probe and its detail in the log.
        Exceptions::assertReported(static fn(RuntimeException $exception
        ): bool => str_contains($exception->getMessage(), 'database: connection refused'));
    }

    public function test_up_loads_nothing_from_third_parties(): void
    {
        // The reason the route is app-owned: the framework's page pulls a font and Tailwind from CDNs the CSP refuses.
        $html = $this->get('/up')->getContent();

        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_up_runs_outside_the_session_stack(): void
    {
        // Sessions live on Redis: inside the web group StartSession would open Redis before the probes run,
        // and a Redis outage would surface as an exception instead of the report the probe exists to give.
        $middleware = Route::getRoutes()->getByName('health')->gatherMiddleware();

        $this->assertNotContains('web', $middleware);
        $this->assertNotContains(StartSession::class, $middleware);
    }

    public function test_the_spa_catch_all_yields_only_the_exact_probe_path(): void
    {
        // The catch-all is registered first and excludes `up` by regex; a path merely starting with it is still the SPA's.
        $this->get('/upcoming')->assertOk()->assertSee('id="app"', false);
        $this->get('/up/')->assertOk()->assertSee('Application up');
    }

    public function test_up_keeps_answering_during_maintenance_mode(): void
    {
        $this->app->maintenanceMode()->activate(['time' => now()->getTimestamp(), 'retry' => 60]);

        try {
            // Still ready - the instance must stay in rotation to serve the maintenance page - but says so.
            $this->get('/up')->assertOk()->assertSee('Down for maintenance');
            $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up', 'maintenance' => true]);
            // Everything else is down.
            $this->get('/')->assertStatus(503);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    private function failDatabaseProbe(): void
    {
        // The service is readonly (Mockery cannot subclass it), so the stub extends it for real.
        $this->app->instance(HealthCheckService::class, new readonly class extends HealthCheckService {
            public function criticalFailures(): array
            {
                return [
                    [
                        'name' => 'database',
                        'ok' => false,
                        'critical' => true,
                        'detail' => 'connection refused',
                        'duration_ms' => 1.0,
                    ]
                ];
            }
        });
    }
}
