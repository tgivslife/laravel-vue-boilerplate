# Authentication

The sign-in doors and the account-security plumbing around them. Two-factor authentication has its own document
([two-factor.md](two-factor.md)); how accounts come to exist and how they end is in
[account-lifecycle.md](account-lifecycle.md).

Every door shares the same account-state gate (`canAuthenticate()`: active and not banned), declares its login method
for the authentication log, and regenerates the session on success. Auth endpoints are enumeration-resistant by
convention: identical responses whether or not an account exists.

## Password login

The classic email + password door (`POST /api/login`), session-based via Sanctum's stateful guard. Disabling it hides
the password tab on the login page and turns the endpoint into a 404 — useful when a deployment standardizes on magic
links or an identity provider.

Brute-force lockout counts failed attempts per email + IP pair (cleared on success, so legitimate users never accrue
pressure); a locked account answers `423 Locked` with a `Retry-After` header. Failed two-factor challenge codes feed the
same bucket. Crossing the threshold mails the owner (see the authentication log below).

| Env                          | Default | Meaning                                   |
|------------------------------|---------|-------------------------------------------|
| `PASSWORD_LOGIN_ENABLED`     | `true`  | The email + password door.                |
| `LOGIN_LOCKOUT_ENABLED`      | `true`  | Count failures and lock on the threshold. |
| `LOGIN_LOCKOUT_MAX_ATTEMPTS` | `5`     | Failures before the lock trips.           |
| `LOGIN_LOCKOUT_DURATION`     | `15`    | Lock duration in minutes.                 |

## Magic-link login

Passwordless sign-in via single-use emailed links. Tokens are stored only as APP_KEY-keyed HMAC-SHA256 hashes and
claimed with one atomic conditional UPDATE, so they survive neither reuse nor a database leak. The emailed link lands on
an inert SPA page; the token is spent only by an explicit button press, so mail scanners and prefetchers cannot burn it.
The mail shows which device requested the link (anyone can request one for any address).

With `MAGIC_LINK_PROVISION` on, a link requested for an unknown email becomes a signup link: the account is created only
when the link is consumed — mailbox ownership proven — never at request time, so the send endpoint stays
enumeration-resistant and cannot mint accounts for other people's addresses. Signup links carry distinct mail copy and a
distinct verify-page prompt. What the created account starts with is described
in [account-lifecycle.md](account-lifecycle.md).

| Env                                                  | Default    | Meaning                                                                                                     |
|------------------------------------------------------|------------|-------------------------------------------------------------------------------------------------------------|
| `MAGIC_LINK_ENABLED`                                 | `true`     | Master switch; disabling also kills outstanding links.                                                      |
| `MAGIC_LINK_PROVISION`                               | `true`     | Consuming a link for an unknown email creates the account.                                                  |
| `MAGIC_LINK_PROVISION_TWO_FACTOR_REQUIRED`           | `true`     | Stamp the two-factor enrollment mandate on accounts this door creates (see [two-factor.md](two-factor.md)). |
| `MAGIC_LINK_TTL_MINUTES`                             | `15`       | Link lifetime.                                                                                              |
| `MAGIC_LINK_REQUEST_MAX_ATTEMPTS` / `_DECAY_MINUTES` | `5` / `15` | Request throttle, per target email and per caller IP (every request counts — sending mail is the cost).     |
| `MAGIC_LINK_CONSUME_MAX_ATTEMPTS` / `_DECAY_MINUTES` | `10` / `1` | Consume throttle, per IP and per (hashed) token.                                                            |

## OIDC identity providers

External sign-in via OpenID Connect: Authorization Code + PKCE, nonce, JWKS ID-token validation. Endpoints come from the
issuer's `/.well-known/openid-configuration` — nothing is configured by hand. A provider is usable only when the master
switch, its own flag and its credentials are all present, so a half-configured provider never exposes a broken login
door.

Per-provider `link_policy` decides how a provider login maps to a local account:

- `explicit` — only identities previously linked from the settings page may sign in. The safe default: possessing an
  external identity grants nothing until the account owner claims it.
- `email` — a first login auto-links to the existing local account matching the provider's *verified* email claim. Never
  creates accounts.
- `provision` — a first login creates the account (JIT), gated by a verified email, an optional membership claim, and a
  hard refusal of emails that already have an account. Only sane when the provider's directory is itself
  administratively controlled. Details in
  [account-lifecycle.md](account-lifecycle.md).

Per-provider `two_factor` decides whether a provider login owes the app-side challenge
(see [two-factor.md](two-factor.md)).

| Env                          | Default | Meaning                          |
|------------------------------|---------|----------------------------------|
| `IDENTITY_PROVIDERS_ENABLED` | `true`  | Master switch for all providers. |

Per provider (`ROEID_*` and `ID_PROVIDER_*`):

| Env                                           | Default    | Meaning                                                                                                                      |
|-----------------------------------------------|------------|------------------------------------------------------------------------------------------------------------------------------|
| `{P}_ENABLED`                                 | `true`     | The provider's own switch.                                                                                                   |
| `{P}_LINK_POLICY`                             | `explicit` | `explicit`, `email`, or `provision` (above).                                                                                 |
| `{P}_PROVISION_CLAIM` / `{P}_PROVISION_VALUE` | *(unset)*  | Optional claim gate for `provision`: the token must carry the claim (and value, if set — arrays are matched by containment). |
| `{P}_TWO_FACTOR`                              | `skip`     | `skip` trusts the IdP's MFA; `require` parks enrolled accounts for the app challenge.                                        |

Credentials live in `config/services.php`: `{P}_ISSUER`, `{P}_CLIENT_ID`, `{P}_CLIENT_SECRET`,
`{P}_REDIRECT_URI`.

## Password reset and forced reset

Forgot-password links ride the framework's password broker; the request endpoint responds identically whether or not the
email has an account. Token expiry and the per-user resend throttle live in `config/auth.php` (`passwords.users`).

Administrators can force a reset instead: the account gets a server-generated temporary password (unambiguous charset,
safe to read aloud) and is blocked until the user chooses their own; every session is destroyed and the remember token
rotated so the old credential dies everywhere at once. The password itself is never audited.

| Env                                                      | Default    | Meaning                    |
|----------------------------------------------------------|------------|----------------------------|
| `PASSWORD_RESET_ENABLED`                                 | `true`     | Forgot-password links.     |
| `PASSWORD_RESET_REQUEST_MAX_ATTEMPTS` / `_DECAY_MINUTES` | `5` / `15` | Request-endpoint throttle. |
| `PASSWORD_RESET_ATTEMPT_MAX_ATTEMPTS` / `_DECAY_MINUTES` | `10` / `1` | Reset-endpoint throttle.   |

## Password-confirmed actions

Destructive self-service actions (account deletion, signing out other sessions, token creation, two-factor
enroll/disable/recovery codes) re-confirm the account password, under one shared per-user throttle so a hijacked session
cannot use them as a password-guessing oracle. Passwordless accounts confirm with the signed-in session itself (or the
typed email, for account deletion).

| Env                                                | Default   | Meaning              |
|----------------------------------------------------|-----------|----------------------|
| `PASSWORD_CONFIRM_MAX_ATTEMPTS` / `_DECAY_MINUTES` | `5` / `1` | Shared per-user cap. |

## Sessions

An app-owned session registry records which sessions belong to which user (session drivers store sessions as opaque
records with no per-user index). It powers the settings page's session list and
"sign out other sessions", and the per-user session list in the admin UI. Stale rows are pruned lazily on read and swept
by `auth:purge-session-registry`.

| Env                              | Default | Meaning                                          |
|----------------------------------|---------|--------------------------------------------------|
| `SESSION_REGISTRY_TOUCH_MINUTES` | `5`     | How often a session's registry row is refreshed. |
| `SESSION_REGISTRY_DISPLAY_LIMIT` | `50`    | Circuit breaker for the sessions settings page.  |

## Personal access tokens

Long-lived API tokens for integrating external systems, managed at `/api/tokens`. Management is session-only and
creation re-confirms the password, so a leaked token can never mint replacements. Every token gets an explicit per-token
expiry, and abilities are the owner's own permission names — scoping can only restrict access, never extend it.
`sanctum.expiration` must stay null.

| Env                                          | Default     | Meaning                                    |
|----------------------------------------------|-------------|--------------------------------------------|
| `PAT_DEFAULT_LIFETIME_DAYS`                  | `30`        | Expiry when the create request names none. |
| `PAT_MAX_LIFETIME_DAYS`                      | `365`       | Cap on requested expiries.                 |
| `PAT_CREATE_MAX_ATTEMPTS` / `_DECAY_MINUTES` | `10` / `60` | Creation throttle per user.                |

## Authentication log

Per-account history of login episodes (successful and failed) with device fingerprints, readable by the account owner in
settings and per user in the admin UI. Remember-me re-logins within a short window fold into the existing episode.
Pruned daily by `auth:purge-authentication-logs`.

Queued owner mails: logins from a never-seen device (rate-capped; a user's first recorded login only seeds their device
history), lockout episodes (one mail per episode), and password changes (both the settings form and the reset link).

| Env                                                        | Default    | Meaning                                  |
|------------------------------------------------------------|------------|------------------------------------------|
| `AUTH_LOG_ENABLED`                                         | `true`     | Record login episodes.                   |
| `AUTH_LOG_RETENTION_DAYS`                                  | `365`      | Pruning horizon.                         |
| `AUTH_LOG_PAGE_SIZE`                                       | `15`       | Page size of the settings log view.      |
| `AUTH_LOG_RESTORATION_WINDOW_MINUTES`                      | `5`        | Remember-me re-login folding window.     |
| `AUTH_LOG_NEW_DEVICE_NOTIFICATION_ENABLED`                 | `true`     | Mail on logins from a never-seen device. |
| `AUTH_LOG_NEW_DEVICE_MAX_NOTIFICATIONS` / `_DECAY_MINUTES` | `3` / `60` | Per-user cap on new-device mails.        |
| `AUTH_LOG_LOCKOUT_NOTIFICATION_ENABLED`                    | `true`     | Mail when the login lockout trips.       |
| `AUTH_PASSWORD_CHANGED_NOTIFICATION_ENABLED`               | `true`     | Mail on password changes.                |
