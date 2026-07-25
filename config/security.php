<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | Forces the URL generator to produce https:// URLs and marks the request as secure, for deployments where TLS
    | terminates upstream (e.g. behind a load balancer) and the app would otherwise see a plain HTTP request.
    |
    | EnvironmentSecurityChecks::assertForEnvironment() requires this to be true outside local/testing, so it is
    | config-driven rather than tied to a hardcoded environment list. Applied via enforceHttps() in
    | SecurityServiceProvider::boot(); ignored while running unit tests.
    |
    */

    'force_https' => (bool) env('SECURITY_FORCE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Security Response Headers (HSTS + CSP)
    |--------------------------------------------------------------------------
    |
    | Emitted by SetSecurityHeaders (appended to the global middleware stack), which also sends the flag-free
    | baseline headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy) wherever a more specific
    | middleware has not already chosen a value.
    |
    | HSTS is only emitted on responses to secure requests (behind an ingress that means a trusted
    | X-Forwarded-Proto: https, or force_https) - browsers ignore it on plain HTTP anyway. `preload` is only
    | emitted when its submission prerequisites hold (include_subdomains and max_age >= 31536000); the preload
    | list rejects anything less, and listing is effectively permanent, so it stays a deliberate opt-in.
    | Deploy with a small max_age first and raise it once TLS at the edge is proven stable - a long max-age
    | already delivered to browsers cannot be recalled.
    |
    | CSP: the base policy is fixed in the middleware (self-hosted SPA, nonce'd scripts, no third parties);
    | the *_src lists below only append sources, so a deployment can allow an extra origin without being able
    | to accidentally drop a protection. The captcha vendor's script origin is allowlisted automatically
    | (script/frame/connect) while the captcha is enabled; hCaptcha and reCAPTCHA span additional hosts -
    | list those here (docs/hardening.md has the per-vendor sets). `report_only` rehearses the policy:
    | violations surface in the browser console but nothing is blocked - deploy with it on, flip it off after
    | a clean click-through. EnvironmentSecurityChecks requires CSP enabled (either mode) outside local/testing.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        'script_src' => explode(',', (string) env('SECURITY_CSP_SCRIPT_SRC', ''))
                |> (fn($x) => array_map(static fn(string $source): string => trim($source), $x))
                |> array_filter(...)
                |> array_values(...),

        'style_src' => explode(',', (string) env('SECURITY_CSP_STYLE_SRC', ''))
                |> (fn($x) => array_map(static fn(string $source): string => trim($source), $x))
                |> array_filter(...)
                |> array_values(...),

        'connect_src' => explode(',', (string) env('SECURITY_CSP_CONNECT_SRC', ''))
                |> (fn($x) => array_map(static fn(string $source): string => trim($source), $x))
                |> array_filter(...)
                |> array_values(...),

        'frame_src' => explode(',', (string) env('SECURITY_CSP_FRAME_SRC', ''))
                |> (fn($x) => array_map(static fn(string $source): string => trim($source), $x))
                |> array_filter(...)
                |> array_values(...),

        'img_src' => explode(',', (string) env('SECURITY_CSP_IMG_SRC', ''))
                |> (fn($x) => array_map(static fn(string $source): string => trim($source), $x))
                |> array_filter(...)
                |> array_values(...),

        'font_src' => explode(',', (string) env('SECURITY_CSP_FONT_SRC', ''))
                |> (fn($x) => array_map(static fn(string $source): string => trim($source), $x))
                |> array_filter(...)
                |> array_values(...),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of proxy IP addresses (or CIDR ranges) whose forwarded headers (X-Forwarded-For, X-Forwarded-Host, etc.)
    | the application should trust.
    | Use '*' to trust all proxies (suitable for platforms like Heroku, Railway, or Render where the proxy IP is dynamic).
    |
    | Use "REMOTE_ADDR" to trust the immediately connecting proxy on platforms with dynamic proxy IPs (Heroku) ??? it resolves to
    | the same behavior as "*" but passes the deployed-environment security checks because it is an explicit choice.
    |
    | Applied via TrustProxies::at() in SecurityServiceProvider::boot(), where the configuration repository is available.
    |
    */

    'trusted_proxies' => explode(',', (string) env('TRUSTED_PROXIES', ''))
            |> (fn($x) => array_map(static fn(string $proxy): string => trim($proxy), $x))
            |> array_filter(...)
            |> array_values(...)
            // TrustProxies only understands the trust-everything wildcard as the
            // bare string "*"; inside an array it would be matched as a literal IP.
            |> (fn(array $proxies): array|string => in_array('*', $proxies, true) ? '*' : $proxies),

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of hostnames the application is allowed to respond to (e.g. "example.com,api.example.com").
    | Requests whose Host header does not match an entry are rejected, preventing host header injection attacks such as
    | poisoned password reset links.
    | Leave empty to disable host validation entirely.
    |
    | The middleware is enabled via $middleware->trustHosts() in bootstrap/app.php and configured via TrustHosts::at() in
    | SecurityServiceProvider::boot().
    | Host validation is skipped automatically in the local environment and while running tests.
    |
    */

    'trusted_hosts' => explode(',', (string) env('TRUSTED_HOSTS', ''))
            |> (fn($x) => array_map(static fn(string $host): string => trim($host), $x))
            |> array_filter(...)
            |> array_values(...),

    /*
    |--------------------------------------------------------------------------
    | Login Lockout
    |--------------------------------------------------------------------------
    |
    | Brute-force protection for the login endpoint.
    | Failed attempts are counted per email + IP pair (not by route middleware), and cleared on a successful login,
    | so legitimate users never accrue lockout pressure.
    |
    | Read via LoginRateLimiter, which tracks attempts in the cache, and enforced in AuthService::login(), which checks
    | `enabled`/`max_attempts` before delegating to the strategy-specific login and returns `accountLocked` for
    | `duration_minutes` once the limit is hit.
    |
    */

    'lockout' => [
        'enabled' => env('LOGIN_LOCKOUT_ENABLED', true),
        'max_attempts' => env('LOGIN_LOCKOUT_MAX_ATTEMPTS', 5),
        'duration_minutes' => env('LOGIN_LOCKOUT_DURATION', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password (Credentials) Login
    |--------------------------------------------------------------------------
    |
    | The classic email + password door.
    | Disabling it hides the password tab on the login page and turns POST /api/login into a 404 - useful  when
    | the deployment standardizes on magic links or an identity provider.
    | At least one login method should remain enabled, or nobody can sign in.
    |
    */

    'password_login' => [
        'enabled' => env('PASSWORD_LOGIN_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Magic Link Login
    |--------------------------------------------------------------------------
    |
    | Passwordless login via single-use emailed links.
    | Tokens are stored only as APP_KEY-keyed HMACs (MagicLinkTokenHasher) and claimed atomically in MagicLinkService,
    | so they survive neither reuse nor a database leak.
    |
    | The request/consume limits feed the `magic-link-request` and `magic-link-consume` named limiters in RateLimitServiceProvider.
    | Both throttle every request (not just failures): the request endpoint sends mail, so volume itself is the abuse vector there.
    |
    | `provision` - when enabled, a link requested for an unknown email becomes a signup link: consuming it
    | creates the account (JIT), for deployments open to public self-registration. The account is only created
    | at consumption - clicking the link proves mailbox ownership; merely requesting a link creates nothing,
    | so the send endpoint stays enumeration-resistant and cannot be used to mint accounts for other people's
    | addresses. New accounts get the `access.self_provision_roles` defaults (none by default - deny-by-default RBAC).
    |
    | `provision_two_factor_required` - stamps the two-factor enrollment mandate (the same per-user flag
    | admins toggle) on accounts this door creates: the mailbox is their only factor, so stricter public
    | deployments can insist a second one is enrolled before the app opens up. Distinct from the OIDC
    | per-provider `two_factor` knob, which only challenges factors that already exist.
    |
    */

    'magic_link' => [
        'enabled' => env('MAGIC_LINK_ENABLED', true),
        'provision' => env('MAGIC_LINK_PROVISION', true),
        'provision_two_factor_required' => env('MAGIC_LINK_PROVISION_TWO_FACTOR_REQUIRED', false),
        'ttl_minutes' => env('MAGIC_LINK_TTL_MINUTES', 15),

        'request_limit' => [
            'max_attempts' => env('MAGIC_LINK_REQUEST_MAX_ATTEMPTS', 5),
            'decay_minutes' => env('MAGIC_LINK_REQUEST_DECAY_MINUTES', 15),
        ],

        'consume_limit' => [
            'max_attempts' => env('MAGIC_LINK_CONSUME_MAX_ATTEMPTS', 10),
            'decay_minutes' => env('MAGIC_LINK_CONSUME_DECAY_MINUTES', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Invitations
    |--------------------------------------------------------------------------
    |
    | First-sign-in links for admin-created accounts: instead of a temporary password read out of band,
    | user creation can mail a single-use link (the `invitation` delivery mode on POST /api/access/users).
    | Invitations ride the magic-link machinery (same hashed tokens, atomic claim, purge command) as a
    | second token purpose, but answer to this switch - not `magic_link.enabled` - so a password-only
    | deployment that keeps the self-serve door closed can still invite.
    |
    | Invited accounts start passwordless. When password login is the only enabled door, creation also
    | flags `require_password_reset`, so the consumed link lands the user in front of the choose-your-password
    | gate (EnsurePasswordResetNotRequired) before the app opens up.
    |
    | `two_factor_required` stamps the per-user enrollment mandate on invited accounts at creation,
    | mirroring `magic_link.provision_two_factor_required`. Off by default: unlike the public
    | self-provisioning door, invited accounts are admin-vetted.
    |
    | Resending (POST /api/access/users/{user}/resend-invitation) revokes the previous link: unlike the
    | self-serve door, both sides of the exchange are known, so there is no slow-mail reason to keep old
    | links alive.
    |
    */

    'invitations' => [
        'enabled' => env('USER_INVITATIONS_ENABLED', true),
        'ttl_days' => env('USER_INVITATION_TTL_DAYS', 7),
        'two_factor_required' => env('USER_INVITATION_TWO_FACTOR_REQUIRED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    |
    | The rules every new password answers to, applied through Password::defaults() (SecurityServiceProvider)
    | so the reset, settings-update and invitation doors share one definition.
    | Length is the primary control (NIST 800-63B); composition rules are deliberately absent. `common_list` points at
    | the committed, sorted denylist under resources/ checked by NotCommonPassword - fully offline, refreshed only by
    | committing a new file. `context_terms` are app-specific words NotPersonalPassword rejects alongside
    | the account's own name and email; the app name (config('app.name')) is always included automatically.
    |
    */

    'password_policy' => [
        'min_length' => env('PASSWORD_POLICY_MIN_LENGTH', 12),
        'common_list' => 'security/common-passwords.txt',
        'context_terms' => ['password', 'parola'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset
    |--------------------------------------------------------------------------
    |
    | Forgot-password links, built on the framework's password broker; token expiry and the per-user resend throttle
    | live in config/auth.php (`passwords.users`).
    | The request endpoint responds identically whether or not the email has an account (PasswordResetService), and the
    | limits below feed the `password-reset-request` / `password-reset-attempt` named limiters in RateLimitServiceProvider,
    | both throttle every request, since the request endpoint sends mail and the reset endpoint guards a token.
    |
    */

    'password_reset' => [
        'enabled' => env('PASSWORD_RESET_ENABLED', true),

        'request_limit' => [
            'max_attempts' => env('PASSWORD_RESET_REQUEST_MAX_ATTEMPTS', 5),
            'decay_minutes' => env('PASSWORD_RESET_REQUEST_DECAY_MINUTES', 15),
        ],

        'attempt_limit' => [
            'max_attempts' => env('PASSWORD_RESET_ATTEMPT_MAX_ATTEMPTS', 10),
            'decay_minutes' => env('PASSWORD_RESET_ATTEMPT_DECAY_MINUTES', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password-Confirmed Actions
    |--------------------------------------------------------------------------
    |
    | Shared cap for endpoints that verify the account password before a destructive action (account deletion, signing out other sessions).
    | Feeds the `password-confirm` named limiter in RateLimitServiceProvider, keyed per user, so a hijacked session
    | cannot use those endpoints as a password-guessing oracle.
    |
    */

    'password_confirm_limit' => [
        'max_attempts' => env('PASSWORD_CONFIRM_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('PASSWORD_CONFIRM_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Captcha (Anti-Abuse Hook)
    |--------------------------------------------------------------------------
    |
    | An optional challenge on the public doors, layered on top of the rate limiters for deployments open to the internet
    | (public self-signup makes the mail-sending endpoints the abuse surface).
    | When enabled, RequireCaptcha demands a `captcha_token` on the doors listed below and asks the bound CaptchaVerifier.
    |
    | The shipped verifier speaks the "siteverify" protocol that Cloudflare Turnstile, hCaptcha and Google reCAPTCHA
    | share - point `verify_url` + `secret` at your vendor, no SDK needed.
    | Anything else: rebind the CaptchaVerifier contract (SecurityServiceProvider).
    | Enabled-but-unconfigured fails loudly; verification transport errors fail closed.
    |
    | `doors`: which endpoints demand the token - any of `login`, `magic_link`, `password_reset`.
    |
    | The SPA side is vendor-neutral too (CaptchaWidget.vue hosts any of the three): GET /api/auth/methods
    | exposes the enforced doors plus `site_key`, `script_url` and `provider` so the widget renders itself.
    |
    */

    'captcha' => [
        'enabled' => env('CAPTCHA_ENABLED', false),
        'provider' => env('CAPTCHA_PROVIDER', 'turnstile'),
        'verify_url' => env('CAPTCHA_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'script_url' => env('CAPTCHA_SCRIPT_URL', 'https://challenges.cloudflare.com/turnstile/v0/api.js'),
        'secret' => env('CAPTCHA_SECRET'),
        'site_key' => env('CAPTCHA_SITE_KEY'),
        'doors' => explode(',', (string) env('CAPTCHA_DOORS', 'login,magic_link,password_reset'))
                |> (fn($x) => array_map(static fn(string $door): string => trim($door), $x))
                |> array_filter(...)
                |> array_values(...),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Registry
    |--------------------------------------------------------------------------
    |
    | App-owned index of which sessions belong to which user (SessionRegistry / user_sessions table),
    | maintained because session drivers store sessions as opaque records with no per-user index.
    | `touch_minutes` throttles how often a registry row is refreshed, so we don't add a write to every request.
    | Stale rows are pruned lazily on read and swept by auth:purge-session-registry.
    |
    */

    'session_registry' => [
        'touch_minutes' => env('SESSION_REGISTRY_TOUCH_MINUTES', 5),

        /*
         * Circuit breaker for the sessions settings page: at most this many rows are rendered
         * (a real user has a handful of live sessions, so exceeding it signals a bug or abuse, not normal use).
         * Sessions beyond the cap remain revocable through "sign out other sessions".
         */
        'display_limit' => env('SESSION_REGISTRY_DISPLAY_LIMIT', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity Providers (OIDC)
    |--------------------------------------------------------------------------
    |
    | External sign-in via OpenID Connect (Authorization Code + PKCE, with nonce and JWKS ID-token validation).
    | Behavior lives here; credentials and issuer URLs live in config/services.php under the provider key.
    | A provider is usable only when the master switch, its own flag and its credentials are all present
    | (IdentityProviderRegistry), so a half-configured provider never exposes a broken login door.
    |
    | `link_policy` - how a provider login maps to a local account:
    |
    |  - 'explicit'  - only identities previously linked from the settings page may sign in. The safe
    |                  default: possessing an external identity grants nothing until the account owner
    |                  claims it.
    |  - 'email'     - a first login auto-links to the existing local account matching the provider's
    |                  *verified* email claim. Never creates accounts.
    |  - 'provision' - a first login creates the account (JIT). Only sane when the provider's directory
    |                  is itself administratively controlled (the Keycloak "ID" realm) - never for a
    |                  public citizen scheme like ROeID, where identity does not imply membership.
    |                  Guardrails: a verified email is required; an email already belonging to a local
    |                  account is refused (auto-linking is the `email` policy's job); the account starts
    |                  with the `access.self_provision_roles` defaults (none by default - deny-by-default
    |                  RBAC); and `provision_claim`/`provision_value` can
    |                  gate creation on a token claim (e.g. a Keycloak realm role), so the rule reads
    |                  "the IdP says this person is a user of this app", not merely "this person exists there".
    |
    | `two_factor` - whether a provider login owes the app-side two-factor challenge:
    |
    |  - 'skip'    - trust the IdP to own MFA for its identities (the default).
    |  - 'require' - park enrolled accounts for the app challenge, exactly like the password and
    |                magic-link doors. Choose this for providers that do not enforce MFA themselves.
    |
    */

    'identity_providers' => [
        'enabled' => env('IDENTITY_PROVIDERS_ENABLED', true),

        'providers' => [
            'roeid' => [
                'enabled' => env('ROEID_ENABLED', true),
                'link_policy' => env('ROEID_LINK_POLICY', 'explicit'),
                'provision_claim' => env('ROEID_PROVISION_CLAIM'),
                'provision_value' => env('ROEID_PROVISION_VALUE'),
                'two_factor' => env('ROEID_TWO_FACTOR', 'skip'),
            ],
            'id' => [
                'enabled' => env('ID_PROVIDER_ENABLED', true),
                'link_policy' => env('ID_PROVIDER_LINK_POLICY', 'explicit'),
                'provision_claim' => env('ID_PROVIDER_PROVISION_CLAIM'),
                'provision_value' => env('ID_PROVIDER_PROVISION_VALUE'),
                'two_factor' => env('ID_PROVIDER_TWO_FACTOR', 'skip'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal Access Tokens
    |--------------------------------------------------------------------------
    |
    | Long-lived API tokens for integrating external systems, managed at
    | /api/tokens. Management is session-only (EnsureSessionAuthenticated)
    | and creation re-confirms the password, so a leaked token can never
    | mint its own replacements.
    |
    | Every token receives an explicit per-token expiry: `expires_in_days`
    | from the create request, capped by `max_lifetime_days` and defaulting
    | to `default_lifetime_days`. sanctum.expiration must stay null - Sanctum
    | applies it globally ON TOP of per-token expiries and would silently
    | cap every integration token.
    |
    | Token abilities are the user's own permission names (spatie), never an
    | independent list: a token may only be scoped to permissions its owner
    | holds at creation ('*' = act fully as the user, the default). Spatie
    | authorization is untouched and always applies; scoped tokens are
    | additionally narrowed by Sanctum's own `ability`/`abilities` route
    | middleware, so scoping can only restrict access, never extend it.
    |
    */

    'personal_access_tokens' => [
        'default_lifetime_days' => env('PAT_DEFAULT_LIFETIME_DAYS', 30),
        'max_lifetime_days' => env('PAT_MAX_LIFETIME_DAYS', 365),

        'create_limit' => [
            'max_attempts' => env('PAT_CREATE_MAX_ATTEMPTS', 10),
            'decay_minutes' => env('PAT_CREATE_DECAY_MINUTES', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication (TOTP)
    |--------------------------------------------------------------------------
    |
    | Authenticator-app second factor (RFC 6238), managed from the security settings page.
    | Enrollment is two-step in TwoFactorService (mint an unconfirmed secret, activate it only once a working code confirms it),
    | recovery codes are stored as bcrypt hashes, and each verified code consumes its time step so it can never be replayed.
    |
    | `window` is how many 30-second steps of clock drift a code is allowed (1 = the current step plus one either side).
    |
    | At login, a credential-verified attempt for an enrolled account is parked in the session
    | (TwoFactorChallengeService) and completed by POST /api/two-factor/challenge within `challenge_ttl_minutes`.
    | Failed challenge codes feed the same email+IP lockout bucket as failed passwords, so
    | code guessing trips security.lockout like password guessing does.
    |
    | Enrollment can be mandated per account via the users.two_factor_required flag (set from user management);
    | A flagged account may authenticate but reaches nothing except the enrollment endpoints until it
    | enrolls (EnsureTwoFactorEnrolled), mirroring the forced-password-reset gate.
    |
    | `change_notification` mails the account owner when the factor is enabled, disabled or reset by an administrator,
    | a silent disable is what an account takeover looks like, so this should stay on.
    |
    */

    'two_factor' => [
        'enabled' => env('TWO_FACTOR_ENABLED', true),
        'window' => env('TWO_FACTOR_WINDOW', 1),
        'challenge_ttl_minutes' => env('TWO_FACTOR_CHALLENGE_TTL_MINUTES', 5),

        'change_notification' => [
            'enabled' => env('TWO_FACTOR_CHANGE_NOTIFICATION_ENABLED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Log
    |--------------------------------------------------------------------------
    |
    | Per-account history of login episodes (successful and failed), written by the listeners in app/Listeners/Auth
    | on the framework's Login/Failed/ Logout events and pruned daily by auth:purge-authentication-logs after `retention_days`.
    |
    | Logins from a device fingerprint the account has never used trigger a queued mail (NewDeviceNotification),
    | capped per user by `rate_limit`.
    | A user's first recorded login only seeds their device history - it never alerts - so enabling this on an existing
    | user base does not cause a notification storm.
    | Failed attempts are logged but only mailed when the login lockout trips (AccountLockedNotification).
    |
    | The remember-me recaller re-fires the Login event; an active session from the same device within
    | `session_restoration_window_minutes` is folded into its existing row instead of logged as a new episode.
    |
    */

    'authentication_log' => [
        'enabled' => env('AUTH_LOG_ENABLED', true),
        'retention_days' => env('AUTH_LOG_RETENTION_DAYS', 365),

        /*
         * Page size for the settings page's read-only log view (Settings\AuthenticationLogController).
         */
        'page_size' => env('AUTH_LOG_PAGE_SIZE', 15),
        'session_restoration_window_minutes' => env('AUTH_LOG_RESTORATION_WINDOW_MINUTES', 5),

        'new_device_notification' => [
            'enabled' => env('AUTH_LOG_NEW_DEVICE_NOTIFICATION_ENABLED', true),

            'rate_limit' => [
                'max_attempts' => env('AUTH_LOG_NEW_DEVICE_MAX_NOTIFICATIONS', 3),
                'decay_minutes' => env('AUTH_LOG_NEW_DEVICE_DECAY_MINUTES', 60),
            ],
        ],

        /*
         * Mailed on the Lockout event, not per failed attempt: crossing the lockout threshold separates a typo from
         * deliberate guessing, and dedup per user for the lockout duration means one mail per episode (SendLockoutNotification).
         * No rate-limit knobs needed - the cadence is bounded by security.lockout itself.
         */
        'lockout_notification' => [
            'enabled' => env('AUTH_LOG_LOCKOUT_NOTIFICATION_ENABLED', true),
        ],

        /*
         * Mailed on the PasswordReset event, which both change paths fire (the settings form and the forgot-password reset).
         * A silent password change is what an account takeover looks like, so this should stay on (SendPasswordChangedNotification).
         */
        'password_changed_notification' => [
            'enabled' => env('AUTH_PASSWORD_CHANGED_NOTIFICATION_ENABLED', true),
        ],
    ],
];
