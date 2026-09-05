# Account lifecycle

How accounts come to exist and how they end. The doors themselves are described in
[authentication.md](authentication.md); the roles and auditing machinery in
[access-control.md](access-control.md).

## Admin-created accounts

Created from user management with a server-generated temporary password (16 characters from an unambiguous charset, so
it survives being read over the phone) and the forced-reset flag: the user signs in with the temporary credential and is
blocked until they choose their own password. The plaintext is returned exactly once and never audited. Initial roles
may be assigned at creation - except the super-admin role, which is refused like everywhere else in the admin API.

## Self-provisioned accounts

Two doors can create accounts on first sign-in, both opt-in:

- **Magic link** (`MAGIC_LINK_PROVISION`): the account is created when a signup link is consumed - mailbox ownership
  proven - never at request time. A pre-existing account with that email is signed into instead; concurrent signup links
  for the same address settle on one account.
- **OIDC `provision` link policy**: for providers whose directory is itself administratively controlled. Guardrails: a
  verified email claim, the optional membership claim gate (`{P}_PROVISION_CLAIM`/`{P}_PROVISION_VALUE` - "the IdP says
  this person is a member", not merely
  "this person exists there"), and a hard refusal when the email already belongs to a local account (auto-linking is the
  stricter `email` policy's job).

Both run through one shared creation path (`SelfProvisioningService`), so the guarantees cannot drift between channels:

- the email is normalized (trimmed, lowercased), so case-variant duplicates cannot exist;
- the email is marked verified - the channel proved the mailbox;
- the account has no password (a first password can be set later from settings; passwordless accounts are first-class
  throughout);
- the configured default roles are assigned - they must be seeded, and a listed-but-missing role fails provisioning
  loudly instead of silently creating an empty role that grants nothing;
- optionally the two-factor enrollment mandate is stamped at birth (magic-link door only today -
  see [two-factor.md](two-factor.md));
- the creation is audited as `user.self_provisioned`, with the account itself as actor.

| Config                                                        | Default   | Meaning                                                                                                                                                                                                               |
|---------------------------------------------------------------|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ACCESS_SELF_PROVISION_ROLES` (`access.self_provision_roles`) | *(empty)* | Comma-separated role names auto-assigned to self-provisioned accounts, whichever channel created them. Listed roles must be seeded. Empty means new accounts can sign in but do nothing until an admin grants access. |

The provisioning switches themselves (`MAGIC_LINK_PROVISION`, `{P}_LINK_POLICY=provision`, the claim gate, the 2FA
mandate) are documented with their doors in [authentication.md](authentication.md).

## Deletion and email tombstoning

Both deletion doors - the admin delete in user management and the self-service delete on the settings page - run the
same retirement mechanics (`AccountRetirementService`):

- API tokens revoked, OIDC identity links deleted (a dead account must not squat its provider subject against a future
  re-registration), every session destroyed, the remember token rotated:
  no credential survives;
- the email is **tombstoned** to `{uuid}@deleted.invalid` (`.invalid` is RFC 2606-reserved, so it can never route) and
  only its APP_KEY-keyed HMAC is kept in `deleted_email_hash`;
- the row is soft-deleted, keeping the authentication log and audit trail attributable.

Consequences of the tombstone:

- the address leaves the unique index, so it can legitimately become an account again - including via self-provisioning;
- "was this address ever an account?" stays answerable without retaining the address:
  `User::onlyTrashed()->whereDeletedEmail($email)` (lookups are case-insensitive - the hash is computed over the
  normalized address);
- the audit `before`-snapshot retains the original address, so the trail keeps its answer to "whose account was
  deleted".

Policy stays with each door: the admin delete refuses self-deletion, runs under the lockout invariants (deleting a
lockout permission's last active holder is refused), and audits
`user.deleted`; the self-service delete confirms with the password (or typed email for passwordless accounts), audits
`user.self_deleted` with the owner as actor, and ends the requesting session.

### Inactivity closure

A third door runs the same retirement mechanics on a schedule (`access:close-inactive-accounts`, daily), governed by the
admin-editable `inactivity_closure` app setting (`{enabled, inactive_days, notice_days}`, disabled by default):

- inactivity is measured against the durable `last_login_at` summary (`created_at` for accounts that never signed in);
- a pre-closure warning is mailed once, `notice_days` before the earliest possible closure, and stamped in
  `inactivity_notice_sent_at`; signing in clears the stamp and withdraws the closure;
- closure happens only after the stamp has aged the full notice window **and** inactivity has reached `inactive_days`,
  so no account is ever closed with less warning than the notice promised - even when the policy is first enabled
  against a backlog of long-dead accounts;
- deactivated and banned accounts are skipped: their owners cannot sign in to stop the clock, so their fate stays an
  administrator's decision;
- the closure is audited as `user.inactivity_closed` with the account itself as actor (the `user.self_provisioned`
  convention for events without a human administrator), and the confirmation mail is routed to the address snapshotted
  before the email was tombstoned.

**After deletion**, the account remains a read-only record in the admin UI: the users list has a
`deleted` status filter, the detail page opens for tombstoned accounts (banner, mutations hidden, authentication and
audit logs readable), and a membership-lookup answers "was this address ever an account?" via the keyed hash. There is
deliberately **no restore** - the email is unrecoverable by design, so deletion is presented as final.
