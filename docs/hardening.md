# Hardening and platform conventions

Cross-cutting security posture and the conventions every feature follows. Domain-specific throttles are documented next
to their endpoints in [authentication.md](authentication.md).

## Transport

The intended deployment terminates TLS upstream (ingress / load balancer) and proxies to the app over plain HTTP, so the
app reconstructs the real transport story from two sources: the forwarded headers of a **trusted** proxy, and the
`SECURITY_FORCE_HTTPS` override. Environment security checks require `force_https` outside local/testing;
`security:diagnose` cross-checks the rest.

### How a request counts as secure

`Request::isSecure()` resolves in a fixed order, and knowing it turns "why is HSTS missing?" from a mystery into a
one-look diagnosis:

1. **A trusted proxy's `X-Forwarded-Proto` wins.** With `TRUSTED_PROXIES` covering the ingress, whatever the ingress
   forwards is the truth — including `http`, so an ingress that proxies its plain-HTTP listener instead of redirecting
   shows up here as missing HSTS. Fix the ingress, not the app.
2. **Otherwise the `force_https` flag decides.** `SecurityServiceProvider` marks the request secure at boot when the
   flag is on — the safety net for direct hits without forwarded headers (health probes) and for misconfigured proxy
   lists. Generated URLs (including links in queued mail) are https either way via `URL::forceHttps()`.

Getting `TRUSTED_PROXIES` wrong degrades more than scheme detection: with an untrusted ingress, every client shares the
ingress IP, so IP-keyed protections (login lockout, rate limiters) collapse into one bucket — one attacker's failures
lock out everyone. `REMOTE_ADDR` (trust the immediately connecting proxy) is the right value behind in-cluster ingresses
with dynamic IPs; list CIDRs when they are stable. Host validation (`TRUSTED_HOSTS`) additionally requires proxies to
pass the original `Host` header through — a mismatch fails loudly with a 400, never silently.

| Env                                | Default    | Meaning                                                                                                                                                                              |
|------------------------------------|------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `SECURITY_FORCE_HTTPS`             | `false`    | Force https:// URLs and mark requests secure. Required true outside local/testing.                                                                                                   |
| `TRUSTED_PROXIES`                  | *(empty)*  | Comma-separated proxy IPs/CIDRs whose forwarded headers are trusted. `*` trusts all (rejected at boot outside local/testing); `REMOTE_ADDR` trusts the immediately connecting proxy. |
| `TRUSTED_HOSTS`                    | *(empty)*  | Comma-separated hostnames the app answers to; empty disables host validation. Skipped in local/testing.                                                                              |
| `SECURITY_HSTS_ENABLED`            | `true`     | Emit the HSTS header (secure responses only; `SetSecurityHeaders`).                                                                                                                  |
| `SECURITY_HSTS_MAX_AGE`            | `31536000` | HSTS max-age in seconds.                                                                                                                                                             |
| `SECURITY_HSTS_INCLUDE_SUBDOMAINS` | `true`     | HSTS includeSubDomains.                                                                                                                                                              |
| `SECURITY_HSTS_PRELOAD`            | `false`    | HSTS preload flag. Withheld from the header unless includeSubDomains and max-age ≥ 31536000 hold (preload-list requirements).                                                        |

### HSTS rollout ratchet

HSTS is a commitment: once a browser stores a long max-age, it cannot be recalled, and certificate problems become
non-bypassable for its whole duration. Ratchet up, each step gated on the previous one proving stable:

1. First deploy with a small `SECURITY_HSTS_MAX_AGE` (e.g. `300`) and
   `SECURITY_HSTS_INCLUDE_SUBDOMAINS=false` — worst-case damage is five minutes.
2. TLS at the edge proven stable → raise max-age to `31536000`. `security:diagnose` notes rollout-mode values so the
   intermediate state stays visible in logs.
3. Every subdomain of the apex confirmed HTTPS-only → `INCLUDE_SUBDOMAINS=true`. It covers subdomains you forgot exist;
   inventory first.
4. `PRELOAD=true` only if certain forever: the browser-embedded list takes months and a browser release cycle to leave.
   The middleware withholds the token until steps 2–3 hold, so a premature flag cannot produce a header the preload list
   would reject.

## Response security headers

`SetSecurityHeaders` (global middleware, so every response is covered: SPA shell, API, health probe, error pages, vendor
dashboards) emits HSTS (above), the Content-Security-Policy, and the flag-free baseline headers. Own all of these
headers in the app only — duplicate CSP headers added at an ingress enforce as an *intersection* of the policies and
break allowed sources confusingly. Static assets under `public/build` are served by the web server and carry no app
headers; that is fine, CSP governs HTML documents.

| Header                   | Value         | Notes                                                                                                                             |
|--------------------------|---------------|-----------------------------------------------------------------------------------------------------------------------------------|
| `X-Content-Type-Options` | `nosniff`     | Filled only if absent, like the two below — a more specific middleware wins (the API group sends `Referrer-Policy: no-referrer`). |
| `X-Frame-Options`        | `DENY`        | Legacy mirror of `frame-ancestors 'none'`.                                                                                        |
| `Referrer-Policy`        | `same-origin` |                                                                                                                                   |

### The base CSP

Fixed in the middleware, not configured — it describes what the app *is* (a self-hosted SPA with no third parties). The
`SECURITY_CSP_*_SRC` env lists only **append** sources: a deployment can allow an extra origin but can never drop a
protection.

| Directive                                | Value                    | Why                                                                                                                                                                                                                                        |
|------------------------------------------|--------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `default-src`, `base-uri`, `form-action` | `'self'`                 | Same-origin fallback for everything not listed.                                                                                                                                                                                            |
| `object-src`                             | `'none'`                 | No plugins, ever.                                                                                                                                                                                                                          |
| `frame-ancestors`                        | `'none'`                 | Clickjacking defense; nobody may embed the app.                                                                                                                                                                                            |
| `script-src`                             | `'self' 'nonce-…'`       | Per-response nonce, stamped on `@vite` tags, the boot script in `app.blade.php`, and Horizon's inline bundle. No `unsafe-inline`, no `unsafe-eval` — the SPA is precompiled SFCs. See tiers below.                                         |
| `style-src`                              | `'self' 'unsafe-inline'` | Vue/Nuxt UI style elements and dev-mode CSS injection are inline by nature. Deliberately **no nonce**: per spec, a nonce in the directive voids `'unsafe-inline'` — do not "harden" this by noncing the blade `<style>` blocks.            |
| `worker-src`                             | `'self' blob:`           | Bundler-managed workers (Vite's dev client, libraries that inline theirs) spawn from `blob:` URLs. `blob:` is harmless here — minting a blob already requires running script — and stays out of `script-src`, where it would be dangerous. |
| `img-src`                                | `'self' data:`           |                                                                                                                                                                                                                                            |
| `font-src`, `connect-src`                | `'self'`                 |                                                                                                                                                                                                                                            |
| `frame-src`                              | `'none'`                 | Opens only for the captcha vendor below.                                                                                                                                                                                                   |

### Per-document script-src tiers

The bundled ops dashboards mount Vue against in-DOM templates; runtime template compilation goes through
`new Function()`, which strict CSP blocks (`EvalError: … 'unsafe-eval'`). Their documents — matched on
`config('horizon.path')` / `config('telescope.path')` — relax `script-src` minimally; CSP is per-document, so these
grants never apply to the SPA or API.

| Documents       | `script-src`                           | Why                                                                                                                                                 |
|-----------------|----------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| everything else | `'self' 'nonce-…'`                     | Strict, eval-free.                                                                                                                                  |
| `/horizon/*`    | `'self' 'nonce-…' 'unsafe-eval'`       | Horizon inlines its whole bundle but supports nonces — the middleware hands the per-response nonce to `Horizon::cspNonce()`. Only eval is added.    |
| `/telescope/*`  | `'self' 'unsafe-inline' 'unsafe-eval'` | Telescope's inline bundle has no nonce hook, so inline must be allowed wholesale — and the nonce must be omitted (it would void `'unsafe-inline'`). |

Horizon's layout is also overridden (`resources/views/vendor/horizon/layout.blade.php`) to drop its hardcoded
fonts.bunny.net stylesheet: the app makes no third-party requests, the CSP would block it anyway, and the dashboard
falls back to system fonts. Re-sync that override when upgrading Horizon.

### Derived sources

Two source sets are computed per request, so the right origins appear without manual env edits:

- **Captcha vendor** (while `CAPTCHA_ENABLED`): the origin of `CAPTCHA_SCRIPT_URL` joins
  `script-src`, `frame-src` and `connect-src`. Vendors that span several hosts need the extras:

  | Provider | Extra CSP sources needed |
    | --- | --- |
  | `turnstile` | none — `https://challenges.cloudflare.com` is derived from the script URL |
  | `hcaptcha` | `SECURITY_CSP_SCRIPT_SRC`/`_FRAME_SRC`/`_CONNECT_SRC`: `https://*.hcaptcha.com` |
  | `recaptcha` | `SECURITY_CSP_SCRIPT_SRC`: `https://www.gstatic.com`; `_FRAME_SRC`: `https://www.google.com` |

- **Vite dev server** (while `npm run dev` serves): its http origin joins
  `script/style/img/font/connect-src` and its `ws://` counterpart joins `connect-src`, so HMR runs under the same
  enforced policy as production. CSP grammar cannot express IPv6 literal hosts — a hot file pointing at `[::1]` cannot
  be allowlisted at all, so `vite.config.js` pins
  `server.host` to `127.0.0.1`; if the dev URL regresses to IPv6 the middleware excludes it and logs a warning naming
  the fix.

### Knobs and rollout

| Env                                                                                             | Default   | Meaning                                                                                                                                                           |
|-------------------------------------------------------------------------------------------------|-----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `SECURITY_CSP_ENABLED`                                                                          | `true`    | Emit the CSP header. Required (either mode) outside local/testing.                                                                                                |
| `SECURITY_CSP_REPORT_ONLY`                                                                      | `false`   | Send `Content-Security-Policy-Report-Only` instead: violations surface in the browser console, nothing is blocked. Deploy true, flip after a clean click-through. |
| `SECURITY_CSP_SCRIPT_SRC` … `_STYLE_SRC`, `_CONNECT_SRC`, `_FRAME_SRC`, `_IMG_SRC`, `_FONT_SRC` | *(empty)* | Comma-separated extra sources appended to the matching directive.                                                                                                 |

When something misbehaves, read the browser console literally: "contains an invalid source … will be ignored" complains
about the policy text itself (not a block); "violates the following directive" names the directive and the URL — match
it against the tables above. A page can look fine with a broken policy (nonce'd scripts and inline styles keep running
while a font or websocket dies quietly), so the acceptance bar is a clean console, not a working-looking page. Not
everything in the console is CSP: secure-context APIs (WebGPU and friends) disappear on plain
`http://` regardless of any header.

## Startup diagnostics

`php artisan security:diagnose` — run it from the container entrypoint (after `config:cache`, before starting
php-fpm/workers). It itemizes every hard boot requirement (`EnvironmentSecurityChecks`, which also throws them all at
once at boot), fails on guaranteed-broken configuration (enabled captcha missing keys, missing Vite build manifest,
leftover `public/hot` file), and warns on topology mistakes that run but misbehave: empty
`TRUSTED_PROXIES` (shared lockout buckets behind an ingress), `APP_URL` host missing from
`TRUSTED_HOSTS` or Sanctum's stateful domains, session-domain mismatch, withheld HSTS preload, report-only CSP left on.
Findings are mirrored to the log channel. Exits non-zero on failures;
`--strict` treats warnings the same. Liveness (DB/Redis/mail) stays with the health probes.

## Rate limiting

Every sensitive endpoint sits behind a named limiter (`RateLimitServiceProvider`). Mail-sending endpoints throttle every
request, not just failures — sending email is the cost, so volume itself is the abuse vector — counted per target
address *and* per caller IP. Token-guarded endpoints throttle per IP and per hashed token; password-confirmed endpoints
share one per-user bucket so a hijacked session cannot become a password-guessing oracle. The credential doors (login and
the two-factor challenge) carry two complementary limits: the per-credential failure lockout (email+IP, counts failures)
and a per-IP volume ceiling (`throttle:login`, counts every request) — the latter bounds password spraying, which fans
out across many emails so no single email's failure bucket ever trips the lockout. The individual knobs live with their
features.

## Captcha hook

An optional challenge layered on top of the limiters for internet-facing deployments, off by default. When enabled, the
configured doors (`login`, `magic_link`, `password_reset`) demand a
`captcha_token` (`RequireCaptcha` middleware, rejecting identically whatever the account state — no enumeration
surface). The shipped verifier speaks the "siteverify" protocol Cloudflare Turnstile, hCaptcha and Google reCAPTCHA
share — point the URL and secret at your vendor, no SDK needed; anything else rebinds the `CaptchaVerifier` contract
(`SecurityServiceProvider`). Enabled-but-unconfigured fails loudly; verification transport errors fail closed.

The SPA side is vendor-neutral too: `CaptchaWidget.vue` loads the vendor script and drives the explicit-render API all
three share, fed by `GET /api/auth/methods` (`captcha_doors`, the public site key, script URL and provider). The login
and forgot-password pages render it automatically on their guarded doors and reset it after every submit (tokens are
single-use). A widget that fails to load leaves the token empty, so the gate fails closed, never silently open.

| Env                  | Default                           | Meaning                                                                                  |
|----------------------|-----------------------------------|------------------------------------------------------------------------------------------|
| `CAPTCHA_ENABLED`    | `false`                           | Master switch for the hook.                                                              |
| `CAPTCHA_PROVIDER`   | `turnstile`                       | `turnstile`, `hcaptcha` or `recaptcha` — names the widget's vendor global.               |
| `CAPTCHA_VERIFY_URL` | Turnstile's siteverify URL        | The vendor's server-side verification endpoint.                                          |
| `CAPTCHA_SCRIPT_URL` | Turnstile's api.js                | The vendor's widget script (use the `?render=explicit` variants for hCaptcha/reCAPTCHA). |
| `CAPTCHA_SECRET`     | *(unset)*                         | The vendor secret; required once enabled. Never leaves the server.                       |
| `CAPTCHA_SITE_KEY`   | *(unset)*                         | The public widget key, exposed to the SPA.                                               |
| `CAPTCHA_DOORS`      | `login,magic_link,password_reset` | Comma-separated doors that demand the token.                                             |

Per-provider values for the two URLs (the defaults are Turnstile's):

| Provider    | `CAPTCHA_VERIFY_URL`                                        | `CAPTCHA_SCRIPT_URL`                                      |
|-------------|-------------------------------------------------------------|-----------------------------------------------------------|
| `turnstile` | `https://challenges.cloudflare.com/turnstile/v0/siteverify` | `https://challenges.cloudflare.com/turnstile/v0/api.js`   |
| `hcaptcha`  | `https://api.hcaptcha.com/siteverify`                       | `https://js.hcaptcha.com/1/api.js?render=explicit`        |
| `recaptcha` | `https://www.google.com/recaptcha/api/siteverify`           | `https://www.google.com/recaptcha/api.js?render=explicit` |

## Conventions

- **Enumeration resistance**: auth endpoints respond byte-identically whether or not an account exists, and user-facing
  copy states outcomes unconditionally (never "if an account exists…").
- **Failure indistinguishability**: unknown, expired and replayed tokens or codes collapse into one error wherever
  distinguishing them would tell a guesser something.
- **Queued notifications carry scalar snapshots only**, never models — and mails that matter to account safety (new
  device, lockout, password changed, two-factor changes, magic links) are all queued so response timing reveals nothing.
- **Secrets at rest**: magic-link tokens and deleted-email membership hashes are APP_KEY-keyed HMACs; TOTP secrets are
  encrypted; recovery codes and passwords are bcrypt hashes.
- **i18n throughout**: English and Romanian for the SPA, API responses and mails (`APP_SUPPORTED_LOCALES`, default
  `en,ro`).
- **Comma-separated env lists** are trimmed and filtered; empty entries are dropped.

## Frontend posture

Vue 3 SPA (plain JS, Nuxt UI, Pinia, file-based routes). Grants are server-confirmed: roles and permissions are never
trusted from cache — the router awaits a fresh `/api/user` before the first navigation and fails closed. Route guards
enforce the forced-password-reset and two-factor enrollment gates client-side while middleware enforces them
server-side; malformed grant payloads degrade to no access, never to more.

| Env                     | Default   | Meaning                                    |
|-------------------------|-----------|--------------------------------------------|
| `CORS_ALLOWED_ORIGINS`  | `APP_URL` | Comma-separated allowed origins.           |
| `APP_SUPPORTED_LOCALES` | `en,ro`   | Locales offered by the SPA, API and mails. |

## Scheduled hygiene

- `auth:purge-magic-link-tokens` — expired and consumed magic-link tokens.
- `auth:purge-session-registry` — stale session-registry rows.
- `auth:purge-authentication-logs` — authentication-log entries past `AUTH_LOG_RETENTION_DAYS`.
- `access:purge-audit-logs` — access-audit entries past `ACCESS_AUDIT_LOG_RETENTION_DAYS`
  (non-positive keeps them forever).
