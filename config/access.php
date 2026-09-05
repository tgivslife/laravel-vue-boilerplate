<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    |
    | Every role and permission the access layer creates or checks uses this guard.
    | It must be the guard the application authenticates with; a mismatch makes lookups silently miss (GuardDoesNotMatch).
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Super admin
    |--------------------------------------------------------------------------
    |
    | Holders of this role bypass every gate, policy and visibility scope (Gate::before + explicit bypass in scopeVisibleTo).
    | The role cannot be deleted through the admin API.
    |
    */

    'super_admin_role' => 'super-admin',

    /*
    |--------------------------------------------------------------------------
    | Self-provision roles
    |--------------------------------------------------------------------------
    |
    | Role names auto-assigned to accounts created by a self-provisioning login door (the OIDC `provision` link policy,
    | the magic-link `provision` switch).
    | The baseline capability tier for deployments open to public signup; empty means new accounts can sign in but do
    | nothing until an admin grants access.
    | Listed roles must be seeded: a missing role fails provisioning loudly instead of silently creating an empty role that grants nothing.
    |
    */

    'self_provision_roles' => explode(',', (string) env('ACCESS_SELF_PROVISION_ROLES', ''))
            |> (fn($x) => array_map(static fn(string $role): string => trim($role), $x))
            |> array_filter(...)
            |> array_values(...),

    /*
    |--------------------------------------------------------------------------
    | Lockout permissions
    |--------------------------------------------------------------------------
    |
    | The escalation-equivalent capabilities: granting roles/permissions to users, and editing what a role grants.
    | The lockout guards protect each one independently - a mutation may neither strip the acting admin's own
    | effective grant of one they held, nor strip a permission's last active holder (super admins count as holders of everything).
    |
    */

    'lockout_permissions' => ['users.manage', 'roles.manage'],

    /*
    |--------------------------------------------------------------------------
    | Privileged permissions
    |--------------------------------------------------------------------------
    |
    | The capabilities that define the administrative tier.
    | Holding one the acting admin lacks puts an account out of that admin's reach entirely (the target ceiling),
    | and holding any at all puts it out of impersonation's reach for everyone but super admins.
    | Deliberately a separate list from lockout_permissions above: that one also drives the last-active-holder invariant,
    | and listing settings.manage there would mean the last settings admin could never be removed.
    |
    */

    'privileged_permissions' => ['users.manage', 'roles.manage', 'settings.manage', 'users.impersonate'],

    /*
    |--------------------------------------------------------------------------
    | Capability vocabulary
    |--------------------------------------------------------------------------
    |
    | The seeder generates "{resource}.{verb}" permissions from this map, plus the standalone permissions listed below and the lockout permissions above.
    | Permissions are code-seeded on purpose - they are the vocabulary the application is written against,
    | while roles are runtime data composed in the admin UI.
    | The boilerplate verb set is view + manage; add finer verbs per resource only when a role actually needs them.
    |
    */

    'resources' => [
        'users' => ['view', 'manage', 'impersonate'],
        'roles' => ['view', 'manage'],
        'settings' => ['manage'],
    ],

    'standalone_permissions' => [],

    /*
    |--------------------------------------------------------------------------
    | Protectables
    |--------------------------------------------------------------------------
    |
    | Models that can carry required-permission rules, keyed by their morph alias.
    | The map doubles as the whitelist the admin API validates against and is merged into the enforced morph map.
    | `label` names the column the record browser displays and searches.
    |
    */

    'protectables' => [
        // 'project' => ['model' => App\Models\Project::class, 'label' => 'name'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope dimensions
    |--------------------------------------------------------------------------
    |
    | App-registered implementations of Contracts\ScopeDimension, resolved from the container.
    | Each dimension both narrows visibleTo() queries and vetoes single-record access.
    | A project with none gets plain RBAC plus required-permission rules.
    |
    */

    'dimensions' => [],

    /*
    |--------------------------------------------------------------------------
    | Rule types
    |--------------------------------------------------------------------------
    |
    | The ability names required-permission rules may target, mirroring the policy verbs.
    |
    */

    'rule_types' => ['view', 'update', 'delete'],

    /*
    |--------------------------------------------------------------------------
    | User browser
    |--------------------------------------------------------------------------
    |
    | The columns the admin users list displays and searches.
    | Kept in config so the access layer never hard-codes the shape of the User model.
    |
    */

    'user_browser' => [
        'search_columns' => ['first_name', 'last_name', 'email'],
        'per_page' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Page size for the audit-trail reads on the admin user detail page, and how long entries live.
    | The trail holds PII on purpose (before-snapshots retain original emails past the tombstone), so
    | `retention_days` bounds it - pruned daily by access:purge-audit-logs.
    | Audit outlives the authentication log deliberately (accountability data ages slower than sign-in telemetry);
    | a non-positive value keeps entries forever, for deployments that must.
    |
    */

    'audit_log' => [
        'page_size' => 15,
        'retention_days' => env('ACCESS_AUDIT_LOG_RETENTION_DAYS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | Impersonation
    |--------------------------------------------------------------------------
    |
    | Whether holders of users.impersonate may sign in as another account (session swap and an exit path; start and stop are audited).
    | With the switch off the start endpoint 404s - the door in does not exist.
    | The way out honors an existing session marker regardless, so flipping this off never strands a live impersonation
    | without an exit (or its ended audit entry).
    | The users.impersonate permission is seeded regardless, so a role composed with it simply lies dormant until a deployment flips this on.
    | Targets above the actor's tier (super admins, privileged-permission holders) are refused unless the actor is a super admin;
    | scope dimensions bound reach like every other per-user action.
    |
    */

    'impersonation' => [
        'enabled' => env('ACCESS_IMPERSONATION_ENABLED', false),
    ],
];
