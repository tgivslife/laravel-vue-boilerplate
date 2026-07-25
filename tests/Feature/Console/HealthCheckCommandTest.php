<?php

namespace Tests\Feature\Console;

use App\Services\Ops\HealthCheckService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    /**
     * Swap the probe runner for a stub reporting exactly the given results.
     * The service is readonly (Mockery cannot subclass it), so the stub extends it for real.
     *
     * @param  list<array{name: string, ok: bool, critical: bool, detail: string, duration_ms: float}>  $probes
     */
    private function stubHealthReport(array $probes): void
    {
        $this->app->instance(HealthCheckService::class, new readonly class($probes) extends HealthCheckService {
            public function __construct(private array $stubbedProbes)
            {
            }

            public function report(bool $criticalOnly = false, ?array $only = null): array
            {
                return array_values(array_filter(
                    $this->stubbedProbes,
                    static fn(array $probe): bool => (!$criticalOnly || $probe['critical'])
                        && ($only === null || in_array($probe['name'], $only, true)),
                ));
            }
        });
    }

    public function test_reports_every_applicable_probe_and_succeeds_when_healthy(): void
    {
        $this->artisan('health:check')
            ->expectsOutputToContain('database')
            ->expectsOutputToContain('cache')
            ->expectsOutputToContain('storage')
            ->expectsOutputToContain('mail')
            ->expectsOutputToContain('All critical health probes passed.')
            ->assertSuccessful();
    }

    public function test_inapplicable_probes_are_skipped(): void
    {
        // Array-driver sessions and a sync queue mean neither probe applies in the test environment.
        $this->artisan('health:check')
            ->doesntExpectOutputToContain('sessions')
            ->doesntExpectOutputToContain('failed jobs')
            ->assertSuccessful();
    }

    public function test_disabled_probes_are_skipped(): void
    {
        config(['health.probes.storage' => false]);

        $this->artisan('health:check')
            ->doesntExpectOutputToContain('storage')
            ->assertSuccessful();
    }

    public function test_json_output_reports_the_overall_verdict(): void
    {
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('health:check --json');

        $this->assertSame(0, $exitCode);

        $report = json_decode(Artisan::output(), true);

        $this->assertTrue($report['healthy']);
        $this->assertContains('database', array_column($report['probes'], 'name'));
    }

    public function test_a_failing_critical_probe_fails_the_command(): void
    {
        $this->stubHealthReport([[
            'name' => 'database',
            'ok' => false,
            'critical' => true,
            'detail' => 'connection refused',
            'duration_ms' => 1.0,
        ]]);

        $this->artisan('health:check')
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('Critical health probes are failing.')
            ->assertFailed();
    }

    public function test_a_failing_non_critical_probe_does_not_fail_the_command(): void
    {
        $this->stubHealthReport([[
            'name' => 'queue',
            'ok' => false,
            'critical' => false,
            'detail' => '99 failed jobs (limit 25)',
            'duration_ms' => 1.0,
        ]]);

        $this->artisan('health:check')
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('All critical health probes passed.')
            ->assertSuccessful();
    }
}
