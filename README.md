# Acme Web

---

## 🔖 Summary

A production-ready Laravel + Vue boilerplate for building closed (invite-only) or public (self-signup) web
applications. It ships the plumbing every serious app needs - authentication, access control, self-service settings,
auditing, and operations tooling - behind configuration switches with locked-down defaults, so a new product starts
from a hardened baseline instead of a blank slate.

**Highlights**

* **Authentication:** passwords, magic links, TOTP two-factor, OIDC single sign-on (any issuer), personal access
  tokens, per-credential lockout and per-IP throttling, enumeration-resistant endpoints
* **Access control:** deny-by-default RBAC with roles/permissions admin, privilege tiers with grant and target
  ceilings, record-level scoping and required permissions, impersonation, inactive-account auto-closure, and
  administrative audit trails for users and roles
* **Self-service settings:** profile, preferences, active sessions, connected identities, authentication log
* **Operations:** Horizon queues, Redis standalone/cluster/sentinel topologies with failover retries, an owned `/up`
  readiness route and health probes, security startup diagnostics, CSP/HSTS response headers, Alpine container packaging
* **Frontend:** Vue 3 SPA (file-based routes, Pinia, Nuxt UI), localized in English and Romanian

## ⚙️ Stack / Tools

* **Backend:** Laravel 13 (PHP 8.3+)
* **Frontend:** Vue.js 3, Nuxt UI
* **Database:** PostgreSQL
* **Caching/State:** Redis

## 🚀 Getting started

```sh
composer run setup      # install, .env, key, migrate, npm install + build
```

Dev services (PostgreSQL, Redis, Mailpit, Keycloak, plus opt-in Horizon/cluster/sentinel profiles) live in
`docker/compose.dev.yaml`.

## 🏷️ Renaming this boilerplate

The placeholder identity is carried by four case-sensitive tokens. A project-wide find-and-replace of each one
(vendor/, node_modules/, public/build/ excluded) is safe - the tokens collide with nothing else:

| Token      | Meaning              | Lives in                                                                                                                                  |
|------------|----------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| `Acme`     | Display name         | `.env(.example)` `APP_NAME`, `public/site.webmanifest` (×2), `lang/{en,ro}/api.php` `app_full_name`, `composer.json` description, `README.md` |
| `acme/web` | Composer package     | `composer.json`                                                                                                                             |
| `acme-web` | npm / compose slug   | `package.json`, `package-lock.json` (×2), `docker/compose.dev.yaml` project `name`                                                          |
| `acme_web` | Database name        | `.env(.example)` `DB_DATABASE`, `docker/compose.dev.yaml` (`POSTGRES_DB` + healthcheck)                                                     |

Quote `APP_NAME` if the new name contains spaces (`APP_NAME="Shop Manager"`), set `APP_URL`, and when you are done
grep for the old tokens to confirm nothing was missed. Runtime surfaces (page titles, SPA logo, mail wordmark,
password-policy context terms) follow `APP_NAME` automatically.

Then finish by hand - these cannot be renamed by substitution:

- Replace the placeholder artwork: `public/favicon.*`, `apple-touch-icon.png`, `web-app-manifest-*.png`,
  `mail-logo.png`, and the mark in `resources/js/components/common/AppLogo.vue`.
- Rewrite the landing copy (`messages.landing.*` in `resources/js/plugins/i18n/locales/*.json`) and the mail full
  name (`lang/{en,ro}/api.php` `app_full_name`) for the real product.
- Rebuild the frontend: `npm run build` (`VITE_APP_NAME` is baked at build time), then `php artisan config:clear`.
- Point the git remote at the new repository and review the `docs/deployment.md` examples and the `Dockerfile`
  `REGISTRY`/`BASE_TAG` args.

## 📚 Documentation

* **Docs index:** [docs/README.md](docs/README.md) - per-domain documents covering features and their configuration side
  by side, plus an env-variable lookup map
    * [Authentication](docs/authentication.md) - sign-in doors, password reset, sessions, tokens, authentication log
    * [Two-factor](docs/two-factor.md) - enrollment, login challenge, the enrollment mandate
    * [Account lifecycle](docs/account-lifecycle.md) - admin creation, self-provisioning, deletion and tombstoning
    * [Access control](docs/access-control.md) - RBAC, privilege tiers, lockout invariants, rules, audit trails
    * [Record scoping](docs/record-scoping.md) - scope dimensions, per-record policies, building a scoped role
    * [Hardening](docs/hardening.md) - transport, rate limiting, platform conventions
    * [Deployment](docs/deployment.md) - container image, probes, first-deploy runbook
    * [Redis](docs/redis.md) - standalone/sentinel/cluster topologies, failover model, the dev HA stack
* **Swagger UI:** `/swagger`
* **OpenAPI Spec:** `/api/docs`
