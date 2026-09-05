# Documentation

Each document covers one domain: what is implemented and, next to every feature, the configuration that drives it.

| Document                                     | Covers                                                                                                                                    |
|----------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| [authentication.md](authentication.md)       | The sign-in doors (password, magic link, OIDC), password reset and forced reset, sessions, personal access tokens, the authentication log |
| [two-factor.md](two-factor.md)               | TOTP enrollment, the login challenge, recovery codes, the per-account enrollment mandate, change notifications                            |
| [account-lifecycle.md](account-lifecycle.md) | How accounts come to exist (admin-created, self-provisioned) and how they end (inactivity closure, retirement, email tombstoning, membership lookups) |
| [access-control.md](access-control.md)       | RBAC, the super-admin and privileged tiers, grant and target ceilings, lockout invariants, required-permission rules, the admin surface, the audit trails |
| [record-scoping.md](record-scoping.md)       | How record-level access composes: scope dimensions, required-permission rules, building a scoped role, performance                        |
| [hardening.md](hardening.md)                 | Transport security, response security headers, rate limiting, platform conventions, scheduled hygiene                                     |
| [deployment.md](deployment.md)               | The container image, startup sequence, environment matrix, health probes, the first-deploy runbook                                        |
| [redis.md](redis.md)                         | The three Redis topologies, the sentinel driver's reliability model, queue semantics under failover, the dev HA stack and drill           |

## Where is my env variable?

| Env prefix                                                                                | Document                                                                                                     |
|-------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------|
| `PASSWORD_LOGIN_*`, `LOGIN_LOCKOUT_*`                                                     | [authentication.md](authentication.md)                                                                       |
| `MAGIC_LINK_*`                                                                            | [authentication.md](authentication.md) (the 2FA mandate flag is explained in [two-factor.md](two-factor.md)) |
| `PASSWORD_RESET_*`, `PASSWORD_CONFIRM_*`                                                  | [authentication.md](authentication.md)                                                                       |
| `IDENTITY_PROVIDERS_*`, `ROEID_*`, `ID_PROVIDER_*`                                        | [authentication.md](authentication.md)                                                                       |
| `SESSION_REGISTRY_*`, `PAT_*`, `AUTH_LOG_*`, `AUTH_PASSWORD_CHANGED_*`                    | [authentication.md](authentication.md)                                                                       |
| `TWO_FACTOR_*`                                                                            | [two-factor.md](two-factor.md)                                                                               |
| `ACCESS_SELF_PROVISION_ROLES`                                                             | [account-lifecycle.md](account-lifecycle.md)                                                                 |
| `ACCESS_IMPERSONATION_ENABLED`                                                            | [access-control.md](access-control.md)                                                                       |
| `config/access.php` keys                                                                  | [access-control.md](access-control.md)                                                                       |
| `SECURITY_*`, `TRUSTED_*`, `CAPTCHA_*`, `LOGIN_REQUEST_*`, `APP_SUPPORTED_LOCALES`, `CORS_ALLOWED_ORIGINS` | [hardening.md](hardening.md)                                                                    |
| `LARAVEL_SCHEDULER_ENABLE`, `LARAVEL_HORIZON_ENABLE`, `LARAVEL_MIGRATE_ENABLE`, `NGINX_*` | [deployment.md](deployment.md) (container toggles)                                                           |
| `DB_KEEPALIVES*`, `DB_MASK_BINDINGS_IN_EXCEPTIONS`                                        | [deployment.md](deployment.md) (database connection knobs)                                                   |
| `REDIS_*` (topology, sentinel, timeouts, DB indexes)                                      | [redis.md](redis.md)                                                                                         |
