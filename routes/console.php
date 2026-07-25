<?php

use Laravel\Horizon\Horizon;
use Laravel\Telescope\TelescopeServiceProvider;

/*
 * The daily purges are staggered through the quiet small hours (app timezone) instead of piling onto the
 * congested 00:00 minute, and pinned to one server so multi-node deployments don't run every purge per node.
 * onOneServer() coordinates through an atomic lock on the default cache store.
 */

/**
 * Prune stale entries from the Telescope database
 */
if (class_exists(TelescopeServiceProvider::class)) {
    Schedule::command('telescope:prune --hours=24')
        ->daily()
        ->environments(['local']);
}

/**
 * Prune expired password reset tokens
 */
Schedule::command('auth:clear-resets')->dailyAt('03:00')->onOneServer();

/**
 * Prune tokens expired for more than specified number of hours
 */
Schedule::command('sanctum:prune-expired --hours=48')->dailyAt('03:10')->onOneServer();

/**
 * Flush expired and consumed magic-link tokens
 */
Schedule::command('auth:purge-magic-link-tokens')->dailyAt('03:20')->onOneServer();

/**
 * Flush authentication log entries past the configured retention period
 */
Schedule::command('auth:purge-authentication-logs')->dailyAt('03:30')->onOneServer();

/**
 * Flush access audit entries past the configured retention period
 */
Schedule::command('access:purge-audit-logs')->dailyAt('03:40')->onOneServer();

/**
 * Flush attribute-level audit entries past the configured retention period
 */
Schedule::command('audit:purge-logs')->dailyAt('03:50')->onOneServer();

/**
 * Flush session registry rows whose sessions have expired
 */
Schedule::command('auth:purge-session-registry')->hourly()->onOneServer();

/**
 * Capture the queue metrics snapshot Horizon's dashboard graphs are built from
 */
if (class_exists(Horizon::class) && config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
}
