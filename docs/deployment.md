# Deployment

Packaging and running the application as a container. The security posture the deployment must satisfy - transport,
trusted proxies, response headers, the boot-time assertions - lives in
[hardening.md](hardening.md); this document covers the image, the startup sequence, the environment matrix, and the
first-deploy runbook.

## The image

`Dockerfile` builds on the [laravel-alpine](https://github.com/tgivslife/laravel-alpine) base
(`stsdockerhub/php:8.5.8-laravel-alpine3.24`): nginx + php-fpm + supervisor + cron in one container, TLS terminated
upstream. Two stages: the `-build` variant (Composer, Node, npm, git)
runs `composer install --no-dev`, `npm ci` and `npm run build`; the runtime stage receives the result owned by
`www-data`, with neither toolchain nor `node_modules`.

```sh
docker build -t acme-web .
# base overridable: --build-arg BASE_TAG=… --build-arg REGISTRY=…
```

Deliberately **not** baked in: `.env`, `APP_KEY`, and the framework caches. Configuration is injected by the
orchestrator at runtime, and the caches are built at startup where that environment actually exists (`config:cache`
freezes `env()` values - caching at image-build time would freeze the build container's emptiness). `.dockerignore`
keeps `.git`, local `.env` files and a stray `public/hot` out of the build context; the build stage sets `APP_ENV=local`
because composer's `package:discover` boots the app, and the boot security assertions would fail the sourceless build
container under the default `production`.

Dev-only packages (Telescope, Clockwork) are `require-dev` and registered conditionally in
`AppServiceProvider` - never add them to `bootstrap/providers.php`, which registers unconditionally and would crash a
`--no-dev` image at first boot.

## Startup sequence

The base entrypoint runs `/docker-entrypoint.d/*.sh` in order and **aborts startup on any failure** - a misconfigured
container dies with the reason in its logs instead of serving weakened traffic. Scripts 1–6 (base) template
php/opcache/fpm/nginx from env and toggle the scheduler/Horizon supervisor programs; the app appends:

| Script                | Does                                                                                                                                                                           | Dies when                                                                                                              |
|-----------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| `7-laravel-optimize`  | Caches config, routes, views, events against the runtime env.                                                                                                                  | Booting fires `EnvironmentSecurityChecks` - any hard security misconfiguration aborts here with the full failure list. |
| `8-security-diagnose` | `php artisan security:diagnose` ([hardening.md](hardening.md#startup-diagnostics)).                                                                                            | Enabled captcha missing keys, missing Vite build manifest, leftover hot file. Topology warnings are logged, not fatal. |
| `9-laravel-migrate`   | `migrate --force --isolated` with a 10× retry; `--isolated` takes a Redis lock so simultaneously starting replicas migrate exactly once. Skip with `LARAVEL_MIGRATE_ENABLE=0`. | The database never becomes reachable.                                                                                  |

Only after all of that does supervisord start nginx/php-fpm (and cron/Horizon per toggles).

## Environment matrix

An unset variable is not missing configuration - the default in `config/*.php` applies. Most of
`.env.example` is bucket-three knobs whose defaults are already the production posture (locked-down toggles, limits,
TTLs); the orchestrator supplies the rest:

| Group                    | Variables                                                                                                                                     | Notes                                                                                                    |
|--------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------|
| Core                     | `APP_ENV=production`, `APP_KEY`, `APP_URL` (https), `APP_DEBUG=false`                                                                         | `APP_KEY` from a secret; boot rejects debug-on and non-https URLs.                                       |
| Backing services         | `DB_*`, `REDIS_*`, `MAIL_*`                                                                                                                   | Credentials have no defaults; the drivers do - see below. Redis topology (standalone/sentinel/cluster) and its knobs: [redis.md](redis.md). |
| Security (boot-enforced) | `SECURITY_FORCE_HTTPS=true`, `TRUSTED_PROXIES=REMOTE_ADDR`, `TRUSTED_HOSTS=<host>`, `SESSION_SECURE_COOKIE=true`, `SECURITY_CSP_ENABLED=true` | Rationale and variants in [hardening.md](hardening.md#transport). Forgetting these fails loudly at boot. |
| Rollout knobs            | `SECURITY_CSP_REPORT_ONLY`, `SECURITY_HSTS_MAX_AGE`, `SECURITY_HSTS_INCLUDE_SUBDOMAINS`                                                       | See the runbook below.                                                                                   |

**Dev-leaning defaults that nothing enforces** - these misbehave silently when forgotten, because their defaults are
legitimate configurations in general, just not this deployment's:

| Variable           | Default if unset                | Set to                                                                           |
|--------------------|---------------------------------|----------------------------------------------------------------------------------|
| `DB_CONNECTION`    | `sqlite`                        | `pgsql` / `mysql`                                                                |
| `QUEUE_CONNECTION` | `database`                      | `redis` (Horizon requires it)                                                    |
| `CACHE_STORE`      | `database`                      | `redis` (lockout counters, the `migrate --isolated` lock)                        |
| `SESSION_DRIVER`   | `database`                      | `redis` (+ `SESSION_CONNECTION` for the dedicated session database)              |
| `MAIL_MAILER`      | `log`                           | the real transport - or every magic link and security mail lands in the log file |
| `LOG_CHANNEL`      | `stack` (file in the container) | `stderr`, so container logs carry the application log                            |

Database connection knobs (`config/database.php`, PostgreSQL) - opt-in, defaults unchanged:

| Variable                                                                            | Default                | Meaning                                                                                                                                                                                                                  |
|-------------------------------------------------------------------------------------|------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `DB_KEEPALIVES`, `DB_KEEPALIVES_IDLE`, `DB_KEEPALIVES_INTERVAL`, `DB_KEEPALIVES_COUNT` | *(unset)*              | libpq TCP keepalives, appended to the DSN only when set. Turn on behind a load balancer or NAT that drops idle connections: Horizon workers hold theirs open between jobs.                                               |
| `DB_MASK_BINDINGS_IN_EXCEPTIONS`                                                    | `true` unless `APP_DEBUG` | Keeps bound values out of `QueryException` messages (the SQL keeps its `?` placeholders), so a failed query never copies personal data into logs or error reports. Off under `APP_DEBUG`, where the values are what you are debugging. |

Container toggles - the base image templates its php/opcache/fpm/nginx configuration from these at startup (entrypoint
scripts 1–6), so they are plain `--env` values, no config files to mount:

| Variable                              | Default            | Meaning                                                                                                                 |
|---------------------------------------|--------------------|-------------------------------------------------------------------------------------------------------------------------|
| `LARAVEL_SCHEDULER_ENABLE`            | `1`                | Supervisor-managed `schedule:work`. Run it in exactly **one** replica/deployment - it is not lock-coordinated.          |
| `LARAVEL_HORIZON_ENABLE`              | `0`                | Horizon under supervisor in this container.                                                                             |
| `LARAVEL_MIGRATE_ENABLE`              | `1`                | App-specific: startup migrations (script 9).                                                                            |
| `TZ`                                  | `Europe/Bucharest` | Container timezone.                                                                                                     |
| `PHP_MAX_EXECUTION_TIME`              | `60`               | Per-script execution limit, seconds. Keep in step with `NGINX_FASTCGI_READ_TIMEOUT`.                                    |
| `PHP_MEMORY_LIMIT`                    | `128M`             | Per-script memory ceiling.                                                                                              |
| `PHP_UPLOAD_MAX_FILESIZE`             | `50M`              | Maximum uploaded file size. Keep in step with `PHP_POST_MAX_SIZE` and `NGINX_CLIENT_MAX_BODY_SIZE`.                     |
| `PHP_POST_MAX_SIZE`                   | `50M`              | Maximum accepted POST body.                                                                                             |
| `PHP_OPCACHE_ENABLE`                  | `1`                | Opcode cache on/off (`PHP_OPCACHE_ENABLE_CLI` for artisan/queue processes, default `1`).                                |
| `PHP_OPCACHE_MEMORY_CONSUMPTION`      | `512`              | Opcache shared memory, MB.                                                                                              |
| `PHP_OPCACHE_INTERNED_STRINGS_BUFFER` | `128`              | Interned-strings memory, MB.                                                                                            |
| `PHP_OPCACHE_MAX_ACCELERATED_FILES`   | `65406`            | Opcache hash-table capacity (scripts).                                                                                  |
| `PHP_OPCACHE_MAX_WASTED_PERCENTAGE`   | `15`               | Wasted-memory threshold before an opcache restart.                                                                      |
| `PHP_OPCACHE_VALIDATE_TIMESTAMPS`     | `0`                | `0` = never re-stat sources - correct for immutable images; code changes require a new container.                       |
| `PHP_OPCACHE_SAVE_COMMENTS`           | `1`                | Keep docblocks in the cache (annotation-reading libraries need them).                                                   |
| `PHP_FPM_PM_MAX_CHILDREN`             | `50`               | FPM worker-pool ceiling; size against `PHP_MEMORY_LIMIT` × children vs. the pod's memory limit.                         |
| `PHP_FPM_PM_START_SERVERS`            | `20`               | Workers started at boot.                                                                                                |
| `PHP_FPM_PM_MIN_SPARE_SERVER`         | `10`               | Minimum idle workers.                                                                                                   |
| `PHP_FPM_PM_MAX_SPARE_SERVERS`        | `30`               | Maximum idle workers.                                                                                                   |
| `NGINX_FASTCGI_READ_TIMEOUT`          | `60s`              | How long nginx waits on php-fpm per request.                                                                            |
| `NGINX_CLIENT_MAX_BODY_SIZE`          | `50m`              | Request-body cap at the nginx layer.                                                                                    |
| `NGINX_SET_REAL_IP_FROM`              | `127.0.0.1`        | Optional: lets **nginx** resolve client IPs (realip module). Not needed for the app - see "Client IP resolution" below. |
| `NGINX_WORKER_RLIMIT_NOFILE`          | `65535`            | Open-file limit for nginx workers.                                                                                      |
| `NGINX_WORKER_CONNECTIONS`            | `4096`             | Connections per nginx worker.                                                                                           |

### Client IP resolution

Two independent mechanisms exist in this container; the app only needs the first:

- **Laravel (the supported path):** with `NGINX_SET_REAL_IP_FROM` left at its inert default, PHP sees the ingress as
  `REMOTE_ADDR`, and `TRUSTED_PROXIES=REMOTE_ADDR` lets Laravel resolve the real client from `X-Forwarded-For` itself.
  Client IPs, lockout buckets and the authentication log are all correct without touching the nginx knob - its only cost
  is that nginx's *own*
  access logs show the ingress IP.
- **nginx realip (optional):** `NGINX_SET_REAL_IP_FROM=<ingress CIDR>` makes nginx rewrite
  `$remote_addr` from `X-Forwarded-For` (`real_ip_recursive on`), so nginx-level logs and limits see the client. Side
  effect: PHP's `REMOTE_ADDR` becomes the *client* address, so
  `TRUSTED_PROXIES=REMOTE_ADDR` would then mean "trust the client as a proxy" - spoofable whenever the ingress *appends*
  to incoming `X-Forwarded-For` chains instead of replacing them. If you enable realip, switch `TRUSTED_PROXIES` to the
  explicit ingress CIDR (a client
  `REMOTE_ADDR` then never matches, and Laravel uses it directly, ignoring forwarded headers).

Pick one owner for client-IP truth; don't mix `REMOTE_ADDR`-keyword trust with an active realip.

## Process topology

One image, role decided by toggles. The straightforward single-service shape enables everything in one container. At
scale, split into a **web** deployment (scheduler on one replica only, Horizon off, migrations on - `--isolated` makes
replicas safe) and a **worker** deployment (`LARAVEL_HORIZON_ENABLE=1`, scheduler and migrations off). Both read the
same env; Horizon's dashboard is served by the web pods at `/horizon` behind the `viewHorizon` gate.

## Health probes

- **`/ping`** - nginx → php-fpm's ping endpoint. Proves the web chain without booting Laravel; this is the image's
  built-in `HEALTHCHECK` and the right **liveness** probe.
- **`/up`** - the readiness route (`HealthController`): runs the critical probes from `config/health.php`, answers 200
  or 500 (failing probe named on the page, its detail in the log; `{"status": "up"|"down", "maintenance": bool}` for
  JSON), and stays 200 in maintenance mode - the instance must remain in rotation to serve the maintenance page, so
  the flag is informational. Mind `TrustHosts`: outside local/testing, a probe that sends the pod IP as its `Host`
  header is **rejected with a 400** - send a real host header, e.g.:

  ```yaml
  readinessProbe:
    httpGet:
      path: /up
      port: 80
      httpHeaders:
        - name: Host
          value: acme.example   # must be in TRUSTED_HOSTS
  ```

## First-deploy runbook

Start with everything on but nothing irreversible:

```env
SECURITY_CSP_REPORT_ONLY=true      # CSP rehearses: violations in the browser console, nothing blocked
SECURITY_HSTS_MAX_AGE=300          # five-minute HSTS memory while TLS at the edge proves itself
SECURITY_HSTS_INCLUDE_SUBDOMAINS=false
SECURITY_HSTS_PRELOAD=false
```

Verify: container logs show scripts 7–9 clean (`security:diagnose` summary included);
`curl -I https://<host>/` shows `Content-Security-Policy-Report-Only` and
`Strict-Transport-Security: max-age=300`; a full click-through of the app (login, captcha if enabled, settings,
`/horizon`) leaves the browser console free of CSP violations.

Then ratchet, each step gated on the previous one staying quiet:

1. `SECURITY_CSP_REPORT_ONLY=false` - enforcing exactly the rehearsed policy.
2. `SECURITY_HSTS_MAX_AGE=31536000` - after TLS/renewal automation has proven itself
   ([hardening.md](hardening.md#hsts-rollout-ratchet) for why this is the irreversible knob).
3. `SECURITY_HSTS_INCLUDE_SUBDOMAINS=true` - only after inventorying every subdomain;
   `PRELOAD` only if certain forever.

Do not add security headers at the ingress - the app owns them ([hardening.md](hardening.md#response-security-headers):
duplicate CSP enforces as an intersection). The ingress owns TLS, the http→https redirect, and forwarding
`X-Forwarded-Proto`/`Host` intact.
