# Two-factor authentication (TOTP)

An authenticator-app second factor (RFC 6238), cross-cutting every sign-in door in
[authentication.md](authentication.md). Managed from the security settings page.

## Enrollment

Enrollment is two-step: a secret is minted unconfirmed and activated only once a working code confirms it, so a setup
the user never finished can never lock them out at login. Secrets are stored encrypted; recovery codes are stored as
bcrypt hashes and shown in plaintext exactly once (with download). Each verified code consumes its 30-second time step,
so a code can never be replayed.

Starting enrollment, disabling the factor, and regenerating recovery codes are password-confirmed (passwordless accounts
confirm with the signed-in session — see the password-confirmed actions section
of [authentication.md](authentication.md)).

## The login challenge

When a credential-verified login hits an enrolled account, the attempt is parked in the session and the browser is sent
to the challenge page; the session opens only after a valid TOTP or recovery code, within the challenge TTL. This
applies to the password door, the magic-link door (the link proves the mailbox, not the second factor — a compromised
inbox alone must never become an account takeover), and OIDC providers configured with `two_factor => 'require'`
(providers whose own MFA you trust use `skip`, the default).

Failed challenge codes feed the same email + IP lockout bucket as failed passwords, so code guessing trips the login
lockout. Wrong TOTP codes, replayed codes and unknown recovery codes all produce one indistinguishable error.

## The enrollment mandate

A per-account flag (`users.two_factor_required`) turns possession of a second factor into a requirement: the account may
authenticate but reaches nothing except the enrollment endpoints until a confirmed enrollment exists
(`EnsureTwoFactorEnrolled`, mirroring the forced-password-reset gate). The SPA routes flagged accounts straight to the
enrollment screen.

The flag is set from user management, or stamped at birth on magic-link-provisioned accounts via
`MAGIC_LINK_PROVISION_TWO_FACTOR_REQUIRED` — for public deployments where the mailbox would otherwise be the account's
only factor. Note the distinction from the per-provider OIDC `two_factor`
knob: that one only challenges factors that already exist; the mandate requires one to exist.

## Change notifications

The account owner is mailed when the factor is enabled, when it is disabled, and when an administrator resets it — a
silent disable is what an account takeover looks like, so this should stay on. Administrative resets are also audited
(see [access-control.md](access-control.md)).

## Configuration

| Env                                      | Default | Meaning                                                          |
|------------------------------------------|---------|------------------------------------------------------------------|
| `TWO_FACTOR_ENABLED`                     | `true`  | Master switch; also suspends enrollment mandates while off.      |
| `TWO_FACTOR_WINDOW`                      | `1`     | Allowed 30-second steps of clock drift (1 = current step ± one). |
| `TWO_FACTOR_CHALLENGE_TTL_MINUTES`       | `5`     | How long a parked login challenge stays completable.             |
| `TWO_FACTOR_CHANGE_NOTIFICATION_ENABLED` | `true`  | Owner mails on enable/disable/admin reset.                       |

Related knobs documented elsewhere: `MAGIC_LINK_PROVISION_TWO_FACTOR_REQUIRED` and the per-provider
`{P}_TWO_FACTOR` policy, both in [authentication.md](authentication.md).
