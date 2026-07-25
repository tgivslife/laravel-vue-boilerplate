<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\HealthCheckService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The full health report for humans and monitoring scripts: every enabled probe, not just the critical subset guarding /up.
 * Exits non-zero when a critical probe fails, so the command doubles as a scriptable check.
 */
#[Signature('health:check
    {--json : Output the report as JSON}')]
#[Description('Run the application health probes and report their status')]
class HealthCheckCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(HealthCheckService $health): int
    {
        $report = $health->report();

        $healthy = array_all($report, static fn(array $probe): bool => $probe['ok'] || !$probe['critical']);

        if ($this->option('json')) {
            $this->line((string) json_encode(['healthy' => $healthy, 'probes' => $report], JSON_PRETTY_PRINT));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Probe', 'Status', 'Critical', 'Detail', 'Duration'],
            array_map(static fn(array $probe): array => [
                $probe['name'],
                $probe['ok'] ? 'ok' : 'FAIL',
                $probe['critical'] ? 'yes' : 'no',
                $probe['detail'],
                "{$probe['duration_ms']} ms",
            ], $report),
        );

        if (!$healthy) {
            $this->error('Critical health probes are failing.');

            return self::FAILURE;
        }

        $this->info('All critical health probes passed.');

        return self::SUCCESS;
    }
}
