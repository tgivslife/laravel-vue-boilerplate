<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Probes
    |--------------------------------------------------------------------------
    |
    | Which health probes run (see App\Services\Ops\HealthCheckService).
    | Critical probes (database, cache, sessions) also guard the /up endpoint (App\Http\Controllers\Ops\HealthController):
    | a failure turns the load balancer's 200 into a 500.
    | Non-critical probes only surface in the health:check report.
    |
    | The sessions probe only applies when sessions live on redis, the queue probe when the queue is database-backed,
    | the horizon probe when the queue runs on redis (where Horizon supervises the workers), and the sentinel probe
    | when REDIS_TOPOLOGY=sentinel; each skips itself silently otherwise.
    |
    | The sentinel probe reports redundancy rather than liveness - sessions and cache keep passing off a process's
    | cached master address while the whole sentinel fleet is down, so it is the only probe that notices a
    | deployment has quietly lost its ability to fail over.
    |
    */

    'probes' => [
        'database' => (bool) env('HEALTH_PROBE_DATABASE', true),
        'cache' => (bool) env('HEALTH_PROBE_CACHE', true),
        'sessions' => (bool) env('HEALTH_PROBE_SESSIONS', true),
        'sentinel' => (bool) env('HEALTH_PROBE_SENTINEL', true),
        'queue' => (bool) env('HEALTH_PROBE_QUEUE', true),
        'horizon' => (bool) env('HEALTH_PROBE_HORIZON', true),
        'storage' => (bool) env('HEALTH_PROBE_STORAGE', true),
        'mail' => (bool) env('HEALTH_PROBE_MAIL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue thresholds
    |--------------------------------------------------------------------------
    |
    | The queue probe reports unhealthy when the oldest runnable job has been waiting longer than max_pending_minutes
    | (a dead worker) or the failed job count exceeds max_failed_jobs (a poisoned queue).
    |
    */

    'queue' => [
        'max_pending_minutes' => (int) env('HEALTH_QUEUE_MAX_PENDING_MINUTES', 15),
        'max_failed_jobs' => (int) env('HEALTH_QUEUE_MAX_FAILED_JOBS', 25),
    ],
];
