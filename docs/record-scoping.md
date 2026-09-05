# Record scoping

How the access layer decides which *records* a user may see and touch, once role-based access control has already
decided what *kinds* of things they may do. Reference material for the underlying vocabulary and invariants lives
in [access-control.md](access-control.md); this document explains the record-level machinery and how to build on it.

## Three layers, three owners

Record access composes three independent answers. Each is defined in a different place, changed at a different cadence,
and none of them knows about the others:

| Layer                     | Answers                                        | Defined by                     | Changed by                |
|---------------------------|------------------------------------------------|--------------------------------|---------------------------|
| Permissions               | "may you do this kind of thing at all?"        | code (seeder vocabulary)       | deploy                    |
| Scope dimensions          | "which slice of the records?"                  | code (one class per dimension) | data (assignment rows)    |
| Required-permission rules | "does this class or record demand extra keys?" | admin UI, at runtime           | any `roles.manage` holder |

A request must pass every layer that has an opinion. Super admins bypass all three.

## How a decision is evaluated

Nothing here is a global scope — endpoints opt in explicitly, so queue jobs and console commands that never ask are
unaffected by construction. A protectable model opts in by using the
`HasRequiredPermissions` trait, which provides both forms of the question:

- **Index queries** call `Model::visibleTo($user, $type)`. The scope adds each registered dimension's `constrain()`
  clause to the query, evaluates class-level rules in PHP (short-circuiting to an empty result on failure), and appends
  record-rule subqueries only when the class has record rules at all. Filtering happens in SQL; no per-row PHP.
- **Single records** call `$model->userCan($user, $type)`, which delegates to
  `AccessScope::allowsRecord()`: super-admin bypass, then each dimension's `allows()` veto, then class-level rules, then
  the record's own rules. Out-of-scope records surface as 404, not 403, so identifiers are not probeable.

Both forms must agree — `constrain()` narrows index queries to exactly the records `allows()` would accept. That
symmetry is the one law of the `ScopeDimension` contract.

## What is wired today — the admin-surface carve-out

Because nothing here is a global scope, a dimension only binds the surfaces that ask. As shipped, those are:

- **Impersonation** — `ImpersonationService` refuses a target `allowsRecord()` vetoes (out-of-scope users 404).
- **Endpoints the application adds** that call `visibleTo()` / `userCan()`, per the walkthrough below.

The shipped **admin user surface does not ask**: everything under `/api/access/users` (browse, export, show, edit,
delete, role sync, per-user sessions and logs, force reset, two-factor reset) is gated by `users.view` /
`users.manage` capabilities alone. A deployment that registers a `User`-claiming dimension therefore bounds
impersonation but **not** the rest of the admin surface — a tenant-scoped admin could still edit or delete
out-of-scope accounts there. Until the surface is wired, treat `users.manage` as a deployment-wide trust grant, not
a tenant-scoped one.

`Policies\ResourcePolicy` is the intended bridge for closing this: extend it (`resource(): 'users'`), register the
policy for `User`, authorize per record in the admin controllers, and constrain the index/export queries. Wiring it
is planned; the base class exists so a deployment that needs it sooner can do it themselves.

## Scope dimensions

A dimension is an application-defined visibility axis (tenant, region, department) registered in
`config/access.php` `dimensions` and resolved from the container. The interface is three methods:
`appliesTo()` declares jurisdiction over a model class, `constrain()` narrows an index query,
`allows()` vetoes one record.

The capability/reach split is the point: roles and permissions never mention the axis, and the axis never mentions
permissions. Ten county operators share one role; only their assignment rows differ. Widening or narrowing a user's
reach is data entry, not an access mutation.

### Walkthrough: limiting users to one county's precincts

1. **Data.** Precincts carry `county_id`; a `county_user` pivot records which counties a user is assigned. The pivot is
   the only place the limitation lives.
2. **The dimension.** A `CountyDimension` implements the contract: `appliesTo()` claims `Precinct`,
   `constrain()` adds `whereIn('county_id', ...)` from the actor's pivot rows (memoized per request), `allows()` is the
   same membership test for one record.
3. **Wiring.** `Precinct` uses `HasRequiredPermissions`; its index endpoints call `visibleTo()`, its single-record
   endpoints call `userCan()`. The dimension class is listed in
   `access.dimensions`.
4. **Per user, forever after.** Grant `precincts.view` through a role, insert the assignment row. No new roles,
   permissions or code per user.

Extending the same axis to another model (users, stations) is one more `appliesTo()` case — the model inherits the
scoping everywhere the access layer is asked.

Two conventions keep dimensions safe:

- **Fail closed.** An actor with no assignment rows sees nothing. "Sees everything" must be an explicit marker (a
  per-user flag), never the absence of rows.
- **Scoping is reach, not capability.** A dimension never substitutes for the permission check; the policy/middleware
  capability gate runs regardless.

## Required-permission rules

Rules are the runtime-composable layer: rows in `required_permissions` managed from the admin record browser, each
saying "acting on this protectable requires holding that permission".

- `protectable_type` + `protectable_id` name the target — a null id covers every record of the class, an id locks one
  record.
- `type` is the guarded action, from the `rule_types` vocabulary (`view`/`update`/`delete`).
- `mode` combines rows into a group: every `all` row is individually required; `any` rows form a pool of which one must
  be held. An empty group passes.

Only models whitelisted in `access.protectables` can carry rules; the whitelist doubles as the admin API's validation
surface. Rules rewrite authorization outcomes the way role grants do, so the management endpoints sit under
`roles.manage`.

## Performance characteristics

The layer is built to cost nothing when unused and little when used:

- `constrain()` adds a WHERE clause to a query the endpoint was already running; the actor's assignment lookup is one
  indexed query per request, memoized.
- Class-level rules load in one grouped query per request; a second grouped query records which classes have record
  rules at all, letting `visibleTo()` skip its subqueries entirely in the common case. Record verdicts are memoized per
  user/record/action.
- `AccessScope` is a scoped singleton: every memo lives exactly one request, so revoking access is effective on the next
  request by construction — no TTL window, no cache invalidation, no persistent store.
- The deployment's job is ordinary database hygiene: index the scoping columns (`precincts.county_id`, the assignment
  pivot) like any tenant key.

A deployment with empty `dimensions` and `protectables` gets plain RBAC and pays none of this.
