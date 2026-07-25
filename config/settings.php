<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-user preferences registry
    |--------------------------------------------------------------------------
    |
    | Every preference a user may store (users.preferences json column), with its default and validation rules.
    | The registry is closed: reading or writing an unregistered key throws instead of silently inventing state.
    |
    | Rules must stay plain strings so the config survives config:cache.
    | The locale whitelist re-derives APP_SUPPORTED_LOCALES because config files cannot read each other; keep it in sync with app.supported_locales.
    |
    | A null locale means "not chosen": the request keeps negotiating the locale from Accept-Language.
    |
    */

    'preferences' => [
        'locale' => [
            'default' => null,
            'rules' => ['nullable', 'string', 'in:'.env('APP_SUPPORTED_LOCALES', 'en,ro')],
        ],
        'theme' => [
            'default' => 'auto',
            'rules' => ['required', 'string', 'in:light,dark,auto'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | App-level settings registry
    |--------------------------------------------------------------------------
    |
    | Admin-editable runtime settings (the access panel's settings page, gated by settings.manage).
    | The database stores overrides only; a key missing from app_settings resolves to its default here.
    | The registry is closed: reading or writing an unregistered key throws.
    |
    | public: true exposes the resolved value on the unauthenticated GET /api/settings endpoint for SPA bootstrap - never flag a secret.
    |
    | type drives the admin editor's input control (text, email, url, announcement); it carries no server-side meaning beyond that.
    |
    | Array-valued settings validate their shape through nested rules, applied under the submitted value ('enabled' validates 'value.enabled').
    |
    | Localized-value convention: a translatable setting stores an object keyed by locale ({"en": ..., "ro": ...}),
    | restricted to the supported locales via the array:<keys> rule, and the frontend resolves the active
    | locale with a fallback chain.
    | The whitelist re-derives APP_SUPPORTED_LOCALES because config files cannot read each other; keep it in sync with app.supported_locales.
    |
    */

    'app' => [
        // Registry order is presentation order: the admin editor lists settings exactly as they appear here.
        'announcement' => [
            'type' => 'announcement',
            'default' => ['enabled' => false, 'level' => 'info', 'message' => []],
            'rules' => ['required', 'array:enabled,level,message'],
            'nested' => [
                'enabled' => ['required', 'boolean'],
                'level' => ['required', 'string', 'in:info,warning,error'],
                'message' => ['present', 'array:'.env('APP_SUPPORTED_LOCALES', 'en,ro')],
                'message.*' => ['nullable', 'string', 'max:200'],
            ],
            'public' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment report
    |--------------------------------------------------------------------------
    |
    | The read-only environment view on the admin settings page (gated by settings.manage),
    | a deployment diagnostic for checking which variables the running container actually carries.
    | Strictly an allowlist - only the names listed here are ever read, so adding a variable is a deliberate act.
    |
    | Values are read from the process environment (env()), which reflects container-level variables even when
    | config is cached and the .env file is not loaded.
    |
    | Secrets never leave the server: any name ending in a secret suffix, or listed under secrets explicitly,
    | reports only whether it is set.
    |
    */

    'environment' => [
        'secret_suffixes' => ['_KEY', '_SECRET', '_PASSWORD', '_TOKEN'],

        // Names the suffixes miss: SMTP usernames are API keys for most transactional providers.
        'secrets' => ['MAIL_USERNAME'],

        'categories' => [
            'application' => ['APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL'],
            'localization' => ['APP_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_SUPPORTED_LOCALES', 'APP_TIMEZONE'],
            'security' => [
                'SECURITY_FORCE_HTTPS', 'TRUSTED_PROXIES', 'TRUSTED_HOSTS', 'CORS_ALLOWED_ORIGINS',
                'SESSION_SECURE_COOKIE', 'LOGIN_LOCKOUT_ENABLED', 'LOGIN_LOCKOUT_MAX_ATTEMPTS',
                'LOGIN_LOCKOUT_DURATION',
            ],
            'session' => ['SESSION_DRIVER', 'SESSION_CONNECTION', 'SESSION_LIFETIME', 'SANCTUM_EXPIRATION'],
            'database' => [
                'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
                'REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_TOPOLOGY',
                'REDIS_SENTINEL_HOSTS', 'REDIS_SENTINEL_SERVICE',
            ],
            'cache_queue' => [
                'CACHE_STORE', 'QUEUE_CONNECTION', 'BROADCAST_CONNECTION', 'FILESYSTEM_DISK',
                'REDIS_QUEUE_DB', 'HORIZON_MAX_PROCESSES',
            ],
            'mail' => [
                'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
                'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
            ],
            'auditing' => [
                'AUDITING_ENABLED', 'AUDIT_CONSOLE', 'AUDIT_RETENTION_DAYS',
                'AUTH_LOG_ENABLED', 'AUTH_LOG_RETENTION_DAYS', 'ACCESS_AUDIT_LOG_RETENTION_DAYS',
            ],
            'health' => ['HEALTH_QUEUE_MAX_PENDING_MINUTES', 'HEALTH_QUEUE_MAX_FAILED_JOBS'],
            'observability' => ['LOG_CHANNEL', 'LOG_LEVEL', 'TELESCOPE_ENABLED', 'CLOCKWORK_ENABLE'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Config report
    |--------------------------------------------------------------------------
    |
    | The read-only config view beside the environment report: where the environment shows what the container
    | carries (variables may be unset), this shows the effective values the application actually runs with,
    | environment merged over defaults, including cached config.
    | Same rules as the environment report: a strict allowlist of dot-notation paths, and secret-suffixed or explicitly
    | listed paths report only whether they are set.
    |
    */

    'config' => [
        // Matched against the end of the final path segment, so both naming styles are caught: app.key, security.captcha.secret, *.api_token.
        'secret_suffixes' => ['key', 'secret', 'password', 'token'],

        'secrets' => [],

        'categories' => [
            'application' => ['app.name', 'app.env', 'app.debug', 'app.url', 'app.timezone'],
            'localization' => ['app.locale', 'app.fallback_locale', 'app.supported_locales'],
            'security' => [
                'security.force_https', 'security.hsts.enabled', 'security.csp.enabled', 'security.csp.report_only',
                'security.trusted_proxies', 'security.trusted_hosts', 'cors.allowed_origins', 'session.secure',
            ],
            'auth_features' => [
                'security.lockout.enabled', 'security.lockout.max_attempts', 'security.lockout.duration_minutes',
                'security.two_factor.enabled', 'security.magic_link.enabled', 'security.captcha.provider',
                'security.identity_providers.enabled', 'security.password_policy.min_length',
            ],
            'session' => ['session.driver', 'session.connection', 'session.lifetime', 'sanctum.expiration'],
            'drivers' => [
                'database.default', 'database.redis.client', 'cache.default', 'queue.default',
                'queue.connections.redis.connection', 'queue.connections.redis.queue', 'horizon.use',
                'filesystems.default', 'broadcasting.default', 'logging.default',
                'mail.default', 'mail.from.address', 'mail.from.name',
            ],
            'access' => [
                'access.guard', 'access.super_admin_role', 'access.self_provision_roles',
                'access.lockout_permissions', 'access.impersonation.enabled', 'access.audit_log.retention_days',
            ],
            'auditing' => [
                'audit.enabled', 'audit.console', 'audit.retention_days',
                'security.authentication_log.enabled', 'security.authentication_log.retention_days',
            ],
            'health' => ['health.probes', 'health.queue.max_pending_minutes', 'health.queue.max_failed_jobs'],
            'observability' => ['telescope.enabled', 'clockwork.enable'],
        ],
    ],
];
