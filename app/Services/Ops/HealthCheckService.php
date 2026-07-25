<?php

namespace App\Services\Ops;

use App\Support\Redis\SentinelInspector;
use App\Support\Redis\SentinelRetryPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Horizon;
use RuntimeException;
use Throwable;

/**
 * Runs the application's health probes: critical ones (database, cache, redis sessions) gate the framework's /up endpoint
 * via the DiagnosingHealth listener, the rest only surface in the health:check report.
 *
 * Probes that do not apply to the current configuration (redis sessions on a database-session deployment,
 * queue-table checks on a non-database queue, the Horizon probe on a non-redis queue) exclude themselves
 * instead of failing, so one service serves every deployment profile.
 *
 * @phpstan-type ProbeResult array{name: string, ok: bool, critical: bool, detail: string, duration_ms: float}
 */
readonly class HealthCheckService
{
    /**
     * Run every enabled, applicable probe.
     *
     * @param  bool  $criticalOnly  Restrict to the probes that gate /up.
     * @param  list<string>|null  $only  Restrict to the named probes (null = all) - used by
     *   redis:sentinel-drill to report what ops would see in each degraded topology state.
     * @return list<ProbeResult>
     */
    public function report(bool $criticalOnly = false, ?array $only = null): array
    {
        $results = [];

        foreach ($this->probes() as $name => $probe) {
            if (!config("health.probes.{$name}", true) || !$probe['applicable']) {
                continue;
            }

            if ($criticalOnly && !$probe['critical']) {
                continue;
            }

            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }

            $results[] = $this->run($name, $probe['critical'], $probe['check']);
        }

        return $results;
    }

    /**
     * The failing critical probes, running only those - /up is polled by load balancers, so the
     * non-critical probes (which write to storage, count queue tables) stay off that hot path.
     *
     * @return list<ProbeResult>
     */
    public function criticalFailures(): array
    {
        return array_values(array_filter(
            $this->report(criticalOnly: true),
            static fn(array $probe): bool => !$probe['ok'],
        ));
    }

    /**
     * @return array<string, array{critical: bool, applicable: bool, check: callable(): string}>
     */
    private function probes(): array
    {
        return [
            'database' => [
                'critical' => true,
                'applicable' => true,
                'check' => $this->checkDatabase(...),
            ],
            'cache' => [
                'critical' => true,
                'applicable' => true,
                'check' => $this->checkCache(...),
            ],
            'sessions' => [
                'critical' => true,
                'applicable' => config('session.driver') === 'redis',
                'check' => $this->checkSessions(...),
            ],
            /*
             * Redundancy visibility, not liveness: the data-path probes above keep passing off cached
             * discovery while the whole sentinel fleet is down, so without this a deployment can quietly lose
             * its ability to fail over and only find out during the failover.
             */
            'sentinel' => [
                'critical' => false,
                'applicable' => config('database.redis.client') === 'phpredis-sentinel',
                'check' => $this->checkSentinel(...),
            ],
            'queue' => [
                'critical' => false,
                'applicable' => $this->queueDriver() === 'database',
                'check' => $this->checkQueue(...),
            ],
            'horizon' => [
                'critical' => false,
                'applicable' => $this->queueDriver() === 'redis' && class_exists(Horizon::class),
                'check' => $this->checkHorizon(...),
            ],
            'storage' => [
                'critical' => false,
                'applicable' => true,
                'check' => $this->checkStorage(...),
            ],
            'mail' => [
                'critical' => false,
                'applicable' => true,
                'check' => $this->checkMail(...),
            ],
        ];
    }

    /**
     * Execute one probe, timing it and turning any throwable into a failure result.
     *
     * @param  callable(): string  $check
     * @return ProbeResult
     */
    private function run(string $name, bool $critical, callable $check): array
    {
        $startedAt = hrtime(true);

        try {
            $detail = $check();
            $ok = true;
        } catch (Throwable $failure) {
            $detail = $failure->getMessage();
            $ok = false;
        }

        return [
            'name' => $name,
            'ok' => $ok,
            'critical' => $critical,
            'detail' => $detail,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 1),
        ];
    }

    private function checkDatabase(): string
    {
        DB::connection()->select('select 1');

        return 'connection '.config('database.default');
    }

    private function checkCache(): string
    {
        $key = 'health-check:'.Str::random(8);

        SentinelRetryPolicy::withoutRetries(function () use ($key): void {
            Cache::put($key, 'ok', 10);

            if (Cache::pull($key) !== 'ok') {
                throw new RuntimeException('cache roundtrip returned a different value');
            }
        });

        return 'store '.config('cache.default');
    }

    /**
     * A single-key read instead of PING: phpredis routes it on both a standalone server and a cluster, where
     * RedisCluster::ping() would demand a node argument.
     */
    private function checkSessions(): string
    {
        $connection = (string) (config('session.connection') ?? 'default');

        // The resolve() is inside the suppression on purpose: opening the connection is itself retried, and on
        // a cold worker that is where the whole budget would go.
        SentinelRetryPolicy::withoutRetries(
            fn() => Redis::connection($connection)->exists('health-check-probe'),
        );

        return "redis connection {$connection}";
    }

    /**
     * Whether the sentinel fleet could actually run a failover right now.
     */
    private function checkSentinel(): string
    {
        $fleet = app(SentinelInspector::class)->inspect();

        $detail = sprintf(
            '%d/%d sentinels answering, %d healthy replica(s)',
            $fleet['answering'],
            $fleet['configured'],
            $fleet['healthyReplicas'],
        );

        if ($fleet['answering'] === 0) {
            throw new RuntimeException(
                'no sentinel answered - master discovery will fail for every new process: '
                .implode('; ', $fleet['failures'])
            );
        }

        if (!$fleet['quorum']) {
            throw new RuntimeException("{$detail} - quorum is not reachable, a failover cannot be elected");
        }

        if (count($fleet['masters']) > 1) {
            throw new RuntimeException(
                $detail.' - sentinels disagree on the master ('.implode(', ', array_keys($fleet['masters'])).')'
            );
        }

        if ($fleet['healthyReplicas'] === 0) {
            throw new RuntimeException("{$detail} - no replica is in sync, there is nothing to promote");
        }

        return $detail.', master '.(array_key_first($fleet['masters']) ?? 'unknown');
    }

    private function checkQueue(): string
    {
        $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        $maxFailed = (int) config('health.queue.max_failed_jobs', 25);

        $oldestAvailableAt = DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')
            ->min('available_at');
        $pendingMinutes = $oldestAvailableAt === null
            ? 0
            : max(0, (int) floor((now()->getTimestamp() - (int) $oldestAvailableAt) / 60));
        $maxPending = (int) config('health.queue.max_pending_minutes', 15);

        if ($pendingMinutes > $maxPending) {
            throw new RuntimeException(
                "oldest pending job has waited {$pendingMinutes}m (limit {$maxPending}m) - is a worker running?"
            );
        }

        if ($failed > $maxFailed) {
            throw new RuntimeException("{$failed} failed jobs (limit {$maxFailed})");
        }

        return "{$failed} failed, oldest pending {$pendingMinutes}m";
    }

    /**
     * Whether a Horizon master supervisor is alive and processing.
     * Horizon's own repository reads through its dedicated redis connection, so the same probe covers standalone and cluster topologies.
     */
    private function checkHorizon(): string
    {
        $masters = app(MasterSupervisorRepository::class)->all();

        if ($masters === []) {
            throw new RuntimeException('horizon is not running - is `php artisan horizon` supervised?');
        }

        $paused = array_filter($masters, static fn(object $master): bool => ($master->status ?? null) === 'paused');

        if (count($paused) === count($masters)) {
            throw new RuntimeException('horizon is paused - resume it with `php artisan horizon:continue`');
        }

        return count($masters).' master supervisor(s) running';
    }

    private function checkStorage(): string
    {
        $disk = (string) config('filesystems.default');
        $path = '.health-check-probe';

        if (!Storage::disk($disk)->put($path, 'ok')) {
            throw new RuntimeException("could not write to disk {$disk}");
        }

        Storage::disk($disk)->delete($path);

        return "disk {$disk} writable";
    }

    private function checkMail(): string
    {
        $mailer = (string) config('mail.default');

        if ($mailer === '' || config("mail.mailers.{$mailer}") === null) {
            throw new RuntimeException("mailer '{$mailer}' is not configured");
        }

        return "mailer {$mailer}";
    }

    private function queueDriver(): ?string
    {
        $connection = (string) config('queue.default');

        return config("queue.connections.{$connection}.driver");
    }
}
