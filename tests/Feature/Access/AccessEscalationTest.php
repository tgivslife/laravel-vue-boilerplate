<?php

namespace Tests\Feature\Access;

use App\Models\User;
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
        $this->putJson("/api/access/users/{$admin->id}/permissions", ['permission_ids' => [
            $this->permission('users.manage')->getKey(),
            $this->permission('settings.manage')->getKey(),
            $this->permission('roles.manage')->getKey(),
            $this->permission('users.impersonate')->getKey(),
        ]])
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

        $this->putJson("/api/access/roles/{$ops->getKey()}/permissions", ['permission_ids' => [
            $this->permission('roles.manage')->getKey(),
            $this->permission('settings.manage')->getKey(),
            $this->permission('users.manage')->getKey(),
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.grant_above_ceiling'));

        $this->assertFalse($ops->fresh()->hasPermissionTo('settings.manage'));
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

        $this->putJson("/api/access/users/{$target->id}/permissions", ['permission_ids' => [
            $this->permission('settings.manage')->getKey(),
        ]])->assertOk();

        $this->assertTrue($target->fresh()->hasDirectPermission('settings.manage'));
    }

    public function test_a_grant_above_the_actors_ceiling_stays_removable(): void
    {
        // The ceiling applies to the added delta only: a payload that keeps widgets.special
        // (which the actor does not hold) while dropping widgets.a is a removal, not a grant.
        $this->actingAsHolderOf('users.manage');
        $target = $this->userWithPermissions('widgets.special', 'widgets.a');

        $this->putJson("/api/access/users/{$target->id}/permissions", ['permission_ids' => [
            $this->permission('widgets.special')->getKey(),
        ]])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasDirectPermission('widgets.special'));
        $this->assertFalse($fresh->hasDirectPermission('widgets.a'));
    }
}
