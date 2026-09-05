<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\Hash;

/**
 * Regression coverage for the privilege-escalation paths: the target ceiling (no mutation may
 * reach an account holding a privileged capability the actor lacks) and the grant ceiling
 * (an actor may only hand out permissions they effectively hold). Each refusal scenario here
 * was a reproduced live escalation before the ceilings landed.
 */
class AccessEscalationTest extends AccessTestCase
{
    private function actingAsHolderOf(string ...$permissions): User
    {
        $user = $this->userWithPermissions(...$permissions);

        $this->actingAsStateful($user);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = $this->createUser();
        $user->assignRole(config('permission.models.role')::findOrCreate(
            config('access.super_admin_role'), config('access.guard')
        ));

        return $user;
    }

    public function test_a_super_admin_cannot_be_taken_over_through_credential_resets(): void
    {
        $this->actingAsHolderOf('users.manage');
        $target = $this->superAdmin();

        $this->deleteJson("/api/access/users/{$target->id}/two-factor")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.target_above_tier'));

        $this->postJson("/api/access/users/{$target->id}/force-password-reset")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.target_above_tier'));

        // The takeover primitive is gone: the password was never replaced.
        $this->assertTrue(Hash::check('password', $target->fresh()->password));
        $this->assertFalse((bool) $target->fresh()->require_password_reset);
    }

    public function test_a_super_admin_cannot_be_banned_or_deactivated(): void
    {
        $this->actingAsHolderOf('users.manage');
        $target = $this->superAdmin();

        $this->patchJson("/api/access/users/{$target->id}", ['banned' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.target_above_tier'));

        $this->patchJson("/api/access/users/{$target->id}", ['is_active' => false])
            ->assertStatus(422);

        $fresh = $target->fresh();
        $this->assertNull($fresh->banned_at);
        $this->assertTrue((bool) $fresh->is_active);
    }

    public function test_an_account_holding_a_privileged_permission_the_actor_lacks_is_out_of_reach(): void
    {
        $this->actingAsHolderOf('users.manage');
        $target = $this->userWithPermissions('settings.manage');

        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'Hijacked'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.target_above_tier'));

        $this->postJson("/api/access/users/{$target->id}/force-password-reset")
            ->assertStatus(422);

        $this->assertNotSame('Hijacked', $target->fresh()->first_name);
    }

    public function test_equal_tier_admins_still_manage_each_other(): void
    {
        // Identical grants: the ceiling is a subset rule, not a flat "holds anything privileged"
        // rule - peer admins must remain each other's recourse.
        $this->actingAsHolderOf('users.manage');
        $peer = $this->userWithPermissions('users.manage');

        $this->patchJson("/api/access/users/{$peer->id}", ['first_name' => 'Renamed'])->assertOk();
        $this->postJson("/api/access/users/{$peer->id}/force-password-reset")->assertOk();

        $this->assertSame('Renamed', $peer->fresh()->first_name);
        $this->assertTrue((bool) $peer->fresh()->require_password_reset);
    }

    public function test_an_admin_cannot_grant_themselves_permissions_they_do_not_hold(): void
    {
        $admin = $this->actingAsHolderOf('users.manage');

        // users.manage stays in the payload: only the three additions are above the ceiling,
        // and keeping the held grant means the self-revocation guard has nothing to say.
        $this->putJson("/api/access/users/{$admin->id}/permissions", [
            'permission_ids' => [
                $this->permission('users.manage')->getKey(),
                $this->permission('settings.manage')->getKey(),
                $this->permission('roles.manage')->getKey(),
                $this->permission('users.impersonate')->getKey(),
            ]
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.grant_above_ceiling'));

        $this->assertFalse($admin->fresh()->hasDirectPermission('settings.manage'));
    }

    public function test_an_admin_cannot_assign_themselves_a_role_above_their_ceiling(): void
    {
        $admin = $this->actingAsHolderOf('users.manage');
        $elevated = config('permission.models.role')::findOrCreate('elevated', config('access.guard'));
        $elevated->givePermissionTo('settings.manage');

        $this->putJson("/api/access/users/{$admin->id}/roles", ['role_ids' => [$elevated->getKey()]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.grant_above_ceiling'));

        $this->assertFalse($admin->fresh()->hasRole('elevated'));
    }

    public function test_a_role_admin_cannot_grow_their_own_role_beyond_their_ceiling(): void
    {
        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('roles.manage');

        $admin = $this->createUser();
        $admin->assignRole($ops);
        $this->actingAsStateful($admin);

        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", [
            'permission_ids' => [
                $this->permission('roles.manage')->getKey(),
                $this->permission('settings.manage')->getKey(),
                $this->permission('users.manage')->getKey(),
            ]
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.grant_above_ceiling'));

        $this->assertFalse($ops->fresh()->hasPermissionTo('settings.manage'));
    }

    public function test_a_role_edit_cannot_strip_a_privileged_grant_from_an_out_of_reach_holder(): void
    {
        // The role surface is the ceiling's back door: the actor cannot touch the target directly, but the
        // target's privileged grant arrives through a role, and editing that role reaches them all the same.
        $this->actingAsHolderOf('users.manage', 'roles.manage');

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('settings.manage');

        $target = $this->createUser();
        $target->assignRole($ops);

        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'Hijacked'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.target_above_tier'));

        // Its own message: the refusal is about a role, so "managing this account" would describe nothing
        // the admin is looking at - and the blocking holder stays unnamed either way.
        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", ['permission_ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.role_holder_above_tier'));

        $this->assertTrue($ops->fresh()->hasPermissionTo('settings.manage'));
        $this->assertTrue($target->fresh()->can('settings.manage'));
    }

    public function test_a_role_cannot_be_deleted_out_from_under_an_out_of_reach_holder(): void
    {
        // Deletion strips everything the role carried, so it is the same demotion by another name.
        $this->actingAsHolderOf('users.manage', 'roles.manage');

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('settings.manage');

        $target = $this->createUser();
        $target->assignRole($ops);

        $this->deleteJson("/api/access/roles/{$ops->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.role_holder_above_tier'));

        $this->assertNotNull($ops->fresh());
        $this->assertTrue($target->fresh()->can('settings.manage'));
    }

    public function test_a_role_edit_cannot_expand_an_out_of_reach_holders_grants(): void
    {
        /*
         * The ceiling is direction-blind over privileged deltas: adding a privileged permission to a role
         * changes the tier composition of every holder - and reshapes whom other admins can reach - so an
         * out-of-reach holder's grants may not be expanded any more than they may be stripped.
         */
        $this->actingAsHolderOf('users.manage', 'roles.manage', 'widgets.a');

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('settings.manage');

        $target = $this->createUser();
        $target->assignRole($ops);

        // No removal at all - settings.manage stays put - yet the privileged addition reaches the
        // out-of-reach holder and is refused, even though the actor holds users.manage itself.
        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", [
            'permission_ids' => [
                $this->permission('settings.manage')->getKey(),
                $this->permission('users.manage')->getKey(),
            ]
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.role_holder_above_tier'));

        $this->assertFalse($target->fresh()->can('users.manage'));

        // A non-privileged addition moves no tier, so ordinary vocabulary growth still lands.
        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", [
            'permission_ids' => [
                $this->permission('settings.manage')->getKey(),
                $this->permission('widgets.a')->getKey(),
            ]
        ])->assertOk();

        $this->assertTrue($target->fresh()->can('widgets.a'));
    }

    public function test_a_role_edit_still_reaches_holders_within_the_actors_tier(): void
    {
        // The guard is scoped to the privileged deltas that move a tier, so ordinary role curation is untouched:
        // an equal-tier holder stays reachable, and a non-privileged grant leaves without a ceiling check.
        $this->actingAsHolderOf('users.manage', 'roles.manage');

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->syncPermissions([$this->permission('users.manage'), $this->permission('widgets.a')]);

        $peer = $this->createUser();
        $peer->assignRole($ops);

        // A privileged removal from a peer: subset semantics, so the peer never outranked the actor.
        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", [
            'permission_ids' => [
                $this->permission('widgets.a')->getKey(),
            ]
        ])->assertOk();

        $this->assertFalse($peer->fresh()->can('users.manage'));

        // A non-privileged removal needs no holder scan at all.
        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", ['permission_ids' => []])
            ->assertOk();

        $this->assertFalse($peer->fresh()->can('widgets.a'));
    }

    public function test_a_role_held_by_a_super_admin_stays_editable(): void
    {
        // Gate::before answers for super admins, so what their roles carry changes nothing about their
        // authority - counting them as out-of-reach holders would privilege-lock every role they hold.
        $this->actingAsHolderOf('users.manage', 'roles.manage');

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('users.manage');

        $this->superAdmin()->assignRole($ops);

        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", ['permission_ids' => []])
            ->assertOk();

        $this->assertFalse($ops->fresh()->hasPermissionTo('users.manage'));
    }

    public function test_a_minted_account_cannot_exceed_its_creator(): void
    {
        $this->actingAsHolderOf('users.manage');
        $elevated = config('permission.models.role')::findOrCreate('elevated', config('access.guard'));
        $elevated->givePermissionTo('settings.manage');

        $this->postJson('/api/access/users', [
            'first_name' => 'Minted', 'last_name' => 'Admin', 'email' => 'minted@example.com',
            'role_ids' => [$elevated->getKey()],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.grant_above_ceiling'));

        $this->assertNull(User::where('email', 'minted@example.com')->first());
    }

    public function test_the_detail_payload_reports_the_admins_reach_over_the_target(): void
    {
        // The SPA renders out-of-reach accounts read-only from these flags instead of
        // surfacing the server's 422s button by button. `manageable` is the target ceiling
        // (subset rule); `impersonable` is the strict impersonation tier, so an equal-tier
        // peer reads manageable but not impersonable.
        $this->actingAsHolderOf('users.view', 'users.manage');

        $plain = $this->createUser();
        $peer = $this->userWithPermissions('users.manage');
        $above = $this->userWithPermissions('settings.manage');

        $this->getJson("/api/access/users/{$plain->id}")->assertOk()
            ->assertJsonPath('data.user.manageable', true)
            ->assertJsonPath('data.user.impersonable', true);
        $this->getJson("/api/access/users/{$peer->id}")->assertOk()
            ->assertJsonPath('data.user.manageable', true)
            ->assertJsonPath('data.user.impersonable', false);
        $this->getJson("/api/access/users/{$above->id}")->assertOk()
            ->assertJsonPath('data.user.manageable', false)
            ->assertJsonPath('data.user.impersonable', false);
    }

    public function test_the_users_list_carries_the_reach_verdicts_per_row(): void
    {
        // The list's row-action menu reads the same flags, so out-of-reach rows lose the
        // menu instead of offering mutations that would 422.
        $this->actingAsHolderOf('users.view', 'users.manage');

        $plain = $this->createUser();
        $above = $this->userWithPermissions('settings.manage');

        $users = collect($this->getJson('/api/access/users')->assertOk()->json('data.users'))->keyBy('id');

        $this->assertTrue($users[$plain->id]['manageable']);
        $this->assertTrue($users[$plain->id]['impersonable']);
        $this->assertFalse($users[$above->id]['manageable']);
        $this->assertFalse($users[$above->id]['impersonable']);
    }

    public function test_nothing_reads_as_out_of_reach_for_a_super_admin(): void
    {
        $this->actingAsStateful($this->superAdmin());
        $above = $this->userWithPermissions('settings.manage');

        $this->getJson("/api/access/users/{$above->id}")->assertOk()
            ->assertJsonPath('data.user.manageable', true)
            ->assertJsonPath('data.user.impersonable', true);
    }

    public function test_the_super_admin_name_cannot_be_minted_by_create_or_rename(): void
    {
        // No super-admin row exists in this install, so the form requests' unique rule has
        // nothing to collide with - the service-level name reservation is the only defense
        // against renaming a held role into the break-glass name (hasRole matches by name).
        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('roles.manage');

        $admin = $this->createUser();
        $admin->assignRole($ops);
        $this->actingAsStateful($admin);

        $this->postJson('/api/access/roles', ['name' => config('access.super_admin_role')])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.reserved_role_name'));

        $this->patchJson("/api/access/roles/{$ops->getKey()}", ['name' => config('access.super_admin_role')])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.reserved_role_name'));

        $this->assertSame('ops', $ops->fresh()->name);
        $this->assertFalse($admin->fresh()->hasRole(config('access.super_admin_role')));
    }

    public function test_a_super_admin_can_grant_anything(): void
    {
        // The super-admin role carries no attached permissions (Gate::before answers for it),
        // so this pins the holdsPermission() bypass: without it they could grant nothing at all.
        $this->actingAsStateful($this->superAdmin());
        $target = $this->createUser();

        $this->putJson("/api/access/users/{$target->id}/permissions", [
            'permission_ids' => [
                $this->permission('settings.manage')->getKey(),
            ]
        ])->assertOk();

        $this->assertTrue($target->fresh()->hasDirectPermission('settings.manage'));
    }

    public function test_a_grant_above_the_actors_ceiling_stays_removable(): void
    {
        // The ceiling applies to the added delta only: a payload that keeps widgets.special
        // (which the actor does not hold) while dropping widgets.a is a removal, not a grant.
        $this->actingAsHolderOf('users.manage');
        $target = $this->userWithPermissions('widgets.special', 'widgets.a');

        $this->putJson("/api/access/users/{$target->id}/permissions", [
            'permission_ids' => [
                $this->permission('widgets.special')->getKey(),
            ]
        ])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasDirectPermission('widgets.special'));
        $this->assertFalse($fresh->hasDirectPermission('widgets.a'));
    }

    public function test_a_foreign_guard_grant_cannot_be_attached_even_through_the_service(): void
    {
        /*
         * Validation (AllExistInGuard) already 422s a foreign-guard id at the API; this pins the write path's
         * own guard scoping (rolesInGuard/permissionsInGuard), so an internal caller that never ran the form
         * request still cannot attach a grant from another guard. Foreign ids drop out exactly like unknown ids.
         */
        $actor = $this->userWithPermissions('users.manage');
        $target = $this->createUser();

        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $foreignRole = config('permission.models.role')::create(['name' => 'foreign', 'guard_name' => 'other-guard']);
        $foreignPermission = config('permission.models.permission')::create([
            'name' => 'foreign.manage', 'guard_name' => 'other-guard',
        ]);

        $service = app(AccessControlService::class);
        $service->syncUserRoles($actor, $target, [$editors->getKey(), $foreignRole->getKey()]);
        $service->syncUserPermissions($actor, $target, [$foreignPermission->getKey()]);

        $fresh = $target->fresh();
        $this->assertSame(['editors'], $fresh->roles()->pluck('name')->all());
        $this->assertSame([], $fresh->permissions()->pluck('name')->all());
    }
}
