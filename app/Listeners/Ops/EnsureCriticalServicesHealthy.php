<?php

namespace App\Listeners\Ops;

use App\Services\Ops\HealthCheckService;
use Illuminate\Foundation\Events\DiagnosingHealth;
use RuntimeException;

/**
 * Turns the framework's /up endpoint into a real health check: the route dispatches DiagnosingHealth,
 * and a thrown exception here turns its 200 into the 500 a load balancer watches for.
 * Only the critical probes run - /up is polled frequently, and a full queue backlog is a paging matter, not a reason
 * to pull the instance out of rotation.
 */
readonly class EnsureCriticalServicesHealthy
{
    public function __construct(private HealthCheckService $health)
    {
    }

    /**
     * Never called directly by application code: Laravel's event auto-discovery scans app/Listeners,
     * subscribes this method to DiagnosingHealth via its type-hint, and the framework's /up health
     * route dispatches the event on every poll.
     * Throwing here is the contract - the route turns an uncaught exception into a 500 response.
     */
    public function handle(DiagnosingHealth $event): void
    {
        $failures = $this->health->criticalFailures();

        if ($failures === []) {
            return;
        }

        $summary = implode('; ', array_map(
            static fn(array $probe): string => "{$probe['name']}: {$probe['detail']}",
            $failures,
        ));

        throw new RuntimeException("Critical health probes failing - {$summary}");
    }
}
