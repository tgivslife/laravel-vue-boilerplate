# Access control

Role-based access control on spatie/laravel-permission, with app-side guardrails layered on top. Configuration lives in
`config/access.php` (table at the end).

## Vocabulary

Permissions are code-seeded vocabulary — the seeder generates `{resource}.{verb}` names from the
`resources` map (plus `standalone_permissions` and the lockout permissions) — because they are what the application is
written against. Roles are runtime data, composed in the admin UI. Everything is created and checked in the single
configured guard; a guard mismatch makes lookups silently miss.

The boilerplate verb set is `view` + `manage`; add finer verbs per resource only when a role actually needs them.

## Super admin

Holders of the configured super-admin role bypass every gate, policy and visibility scope (`Gate::before` plus explicit
bypass in visibility scopes). The role is deliberately unmanageable through the admin API: it cannot be renamed or
deleted, no other role may be created or renamed into its name (even in installs that never seeded the row - `hasRole`
matches by name), and no payload may grant or strip its membership (privilege escalation / neutralizing break-glass). Membership
changes happen only via seeder or console. The target ceiling below additionally puts super-admin accounts out of every
other actor's administrative reach, so their other roles are editable only from the super-admin tier itself.

## Mutation invariants

Every access mutation goes through one write path (`AccessControlService`): a single transaction serialized on the
lockout permission rows, verified against the invariants before commit:

- **self-revocation** — the acting admin cannot strip their own effective grant of a lockout permission they held when
  the mutation began;
- **last man standing** — a mutation cannot strip a lockout permission's last active holder (super admins count as
  holders of everything; deactivating, banning or deleting the last holder counts as stripping);
- **target ceiling** — no mutation may touch an account holding the super-admin role or a privileged permission the
  actor lacks (subset semantics: equal-tier admins keep managing each other; super admins bypass);
- **grant ceiling** — permissions and roles being *added* must sit within what the actor effectively holds (super
  admins hold everything). Removals are exempt, so a grant above the actor's ceiling stays removable, never re-growable.

The two lockout invariants hold independently for each configured lockout permission.

## Required-permission rules and dimensions

Whitelisted "protectable" models can carry required-permission rule groups — class-level (all records of a protectable)
or per-record, per rule type (`view`/`update`/`delete`), with a group mode — managed from the admin UI through a record
browser. Rules rewrite authorization outcomes the way role grants do, so they sit under `roles.manage`.

Deployments can additionally register scope dimensions (`ScopeDimension` implementations) that both narrow `visibleTo()`
queries and veto single-record access on every surface that asks — the shipped admin user surface among them, scoped
end to end through `UserPolicy` and `visibleTo()` (impersonation included). A project with none gets plain RBAC plus
the rules. How the layers compose, a scoped-role walkthrough and the performance story:
[record-scoping.md](record-scoping.md).

## Admin surface

- **Users**: browse/search/export (columns and page size configurable), account facts editing (name, email verification,
  activation, ban state and reason, the two-factor enrollment mandate), role and direct-permission sync, force password
  reset, two-factor reset (owner notified by mail), delete (see [account-lifecycle.md](account-lifecycle.md)); per-user
  session list and authentication log. When impersonation is enabled, `users.impersonate` holders can sign in as a user
  (session swap with a persistent banner and exit path; access administration, token and credential surfaces — including
  connecting new sign-in identities — are blocked for the borrowed session). Targets above the actor's tier — super
  admins and privileged-permission holders — are refused unless the actor is a super admin. Deleted accounts stay reachable
  read-only: a `deleted` status filter, a read-only detail view, and a membership lookup by original email; audit-trail
  actors keep their names after their own deletion, flagged as deleted.
- **Roles**: create, rename, delete (never the super-admin role), permission sync.
- **Rules**: class and record rule-group management for protectables.
- Coverage and risk summaries on the users list.

## Audit trail

`AccessAuditor` writes one trail for two kinds of events — the line for what belongs is "events that add or remove a way
into an account, no matter who performed them":

- **Administrative mutations**, recorded inside the guarded transactions with scalar before/after snapshots (no-op
  mutations write nothing): `user.created`, `user.account_updated`,
  `user.roles_synced`, `user.permissions_synced`, `user.password_reset_forced`,
  `user.two_factor_reset`, `user.deleted`, `role.created`, `role.renamed`, `role.deleted`,
  `role.permissions_synced`, `rules.class_synced`, `rules.record_synced`; impersonation brackets its borrowed-identity
  window with `user.impersonation_started` / `user.impersonation_ended`. Every exit path writes the `ended` entry —
  explicit stop, logout, even a mid-flight cutoff — but the marker lives in the session, so a `started` without a
  matching `ended` means the borrowed session was destroyed out-of-band (target deleted, forced reset, expiry), not that
  anything was concealed.
- **Self-service security events**, with the account owner as actor: `user.two_factor_enabled`,
  `user.two_factor_disabled`, `user.identity_linked`, `user.identity_unlinked`,
  `user.self_provisioned`, `user.self_deleted`, `user.password_changed`.

Entries carry actor, subject, before/after and IP, and are viewable per user in the admin UI. The trail holds PII on
purpose (before-snapshots retain original emails past the tombstone), so it is retention-pruned daily by
`access:purge-audit-logs`; a non-positive retention keeps entries forever, for deployments that must.

## Configuration (`config/access.php`)

| Key                                                            | Default                            | Meaning                                                                                                                                                  |
|----------------------------------------------------------------|------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|
| `guard`                                                        | `web`                              | The guard every role/permission is created and checked in.                                                                                               |
| `super_admin_role`                                             | `super-admin`                      | The break-glass tier described above.                                                                                                                    |
| `self_provision_roles` (`ACCESS_SELF_PROVISION_ROLES`)         | *(empty)*                          | Defaults for self-provisioned accounts — see [account-lifecycle.md](account-lifecycle.md).                                                               |
| `lockout_permissions`                                          | `users.manage`, `roles.manage`     | The capabilities the self-revocation and last-man-standing invariants protect.                                                                           |
| `privileged_permissions`                                       | the four admin capabilities        | The capabilities defining the administrative tier: the target ceiling and the impersonation tier read this list, not `lockout_permissions`.              |
| `resources`                                                    | `users`, `roles` (`view`/`manage`) | The seeded permission vocabulary.                                                                                                                        |
| `standalone_permissions`                                       | *(empty)*                          | Extra seeded permissions outside the resource map.                                                                                                       |
| `protectables`                                                 | *(empty)*                          | Models (by morph alias) that can carry required-permission rules; doubles as the admin API whitelist. `label` names the column the record browser shows. |
| `dimensions`                                                   | *(empty)*                          | Registered `ScopeDimension` implementations.                                                                                                             |
| `rule_types`                                                   | `view`, `update`, `delete`         | Ability names rules may target.                                                                                                                          |
| `user_browser`                                                 | name/email columns, 25/page        | Admin users list columns and page size.                                                                                                                  |
| `audit_log.page_size`                                          | `15`                               | Page size of the admin audit-trail view.                                                                                                                 |
| `audit_log.retention_days` (`ACCESS_AUDIT_LOG_RETENTION_DAYS`) | `730`                              | Pruning horizon for audit entries; non-positive keeps them forever. Deliberately longer than the authentication log's.                                   |
| `impersonation.enabled` (`ACCESS_IMPERSONATION_ENABLED`)       | `false`                            | Whether `users.impersonate` holders may sign in as another account. Off: starting 404s; an in-flight impersonation keeps its exit.                       |
