<?php

return [

    'enabled' => env('AUDITING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Audit Implementation
    |--------------------------------------------------------------------------
    |
    | The application's own Audit model: adds the impersonator relation on top
    | of the package implementation.
    |
    */

    'implementation' => App\Models\Audit::class,

    /*
    |--------------------------------------------------------------------------
    | User Morph prefix & Guards
    |--------------------------------------------------------------------------
    |
    | The user resolver only checks the web guard: it is the guard the
    | application authenticates with (see config/access.php), and a mismatch
    | would silently attribute audits to no one.
    |
    */

    'user' => [
        'morph_prefix' => 'user',
        'guards' => [
            'web',
        ],
        'resolver' => OwenIt\Auditing\Resolvers\UserResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Resolvers
    |--------------------------------------------------------------------------
    |
    | Each key is an audits-table column filled by its resolver when an entry
    | is written. impersonator_id records the administrator whose session was
    | borrowed when a write happens mid-impersonation, so the trail names both
    | parties instead of borrowing the actor.
    |
    */
    'resolvers' => [
        'ip_address' => OwenIt\Auditing\Resolvers\IpAddressResolver::class,
        'user_agent' => OwenIt\Auditing\Resolvers\UserAgentResolver::class,
        'url' => OwenIt\Auditing\Resolvers\UrlResolver::class,
        'impersonator_id' => App\Services\Audit\ImpersonatorResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Events
    |--------------------------------------------------------------------------
    |
    | The Eloquent events that trigger an Audit.
    |
    */

    'events' => [
        'created',
        'updated',
        'deleted',
        'restored',
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | Enable the strict mode when auditing?
    |
    */

    'strict' => false,

    /*
    |--------------------------------------------------------------------------
    | Global exclude
    |--------------------------------------------------------------------------
    |
    | Have something you always want to exclude by default? - add it here.
    | Note that this is overwritten (not merged) with local exclude
    |
    */

    'exclude' => [],

    /*
    |--------------------------------------------------------------------------
    | Empty Values
    |--------------------------------------------------------------------------
    |
    | Audits whose old and new values are both empty (e.g. an update touching
    | only excluded attributes) are dropped: no-op mutations write no audit
    | entry, matching the access trail's convention.
    |
    */

    'empty_values' => false,
    'allowed_empty_values' => [
        'retrieved',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Array Values
    |--------------------------------------------------------------------------
    |
    | Array-cast attributes (an app setting's json value, snapshot lists) must
    | survive into old/new values, so arrays are allowed despite the package
    | default - audited models stay responsible for keeping them small.
    |
    */
    'allowed_array_values' => true,

    /*
    |--------------------------------------------------------------------------
    | Audit Timestamps
    |--------------------------------------------------------------------------
    |
    | Should the created_at, updated_at and deleted_at timestamps be audited?
    |
    */

    'timestamps' => false,

    /*
    |--------------------------------------------------------------------------
    | Audit Threshold
    |--------------------------------------------------------------------------
    |
    | Specify a threshold for the amount of Audit records a model can have.
    | Zero means no limit.
    |
    */

    'threshold' => 0,

    /*
    |--------------------------------------------------------------------------
    | Audit Driver
    |--------------------------------------------------------------------------
    |
    | The default audit driver used to keep track of changes.
    |
    */

    'driver' => 'database',

    /*
    |--------------------------------------------------------------------------
    | Audit Driver Configurations
    |--------------------------------------------------------------------------
    |
    | Available audit drivers and respective configurations.
    |
    */

    'drivers' => [
        'database' => [
            'table' => 'audits',
            'connection' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Queue Configurations
    |--------------------------------------------------------------------------
    |
    | Available audit queue configurations.
    |
    */

    'queue' => [
        'enable' => false,
        'connection' => 'sync',
        'queue' => 'default',
        'delay' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Console
    |--------------------------------------------------------------------------
    |
    | Console events (seeders, commands, tinker) are audited by default - with
    | a null user - so administrative writes outside HTTP still leave a trace.
    |
    */

    'console' => (bool) env('AUDIT_CONSOLE', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Application-owned key (not part of the package config): how long
    | audit:purge-logs keeps entries. Non-positive disables pruning for
    | deployments that must keep the trail indefinitely, mirroring
    | access.audit_log.retention_days.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),
];
