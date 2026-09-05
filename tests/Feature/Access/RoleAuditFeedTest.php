<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Tests\Support\UserAllowlistDimension;

/**
 * The role-surface audit feed (GET /api/access/roles/audit-logs): the durable record of role mutations.
 * Roles hard-delete, so unlike the per-user trail there is no tombstoned subject to bind - the feed must keep a
 * deleted role's whole history readable from snapshots alone, and the actor redaction of the user trail must
 * carry over unchanged.
 */
class RoleAuditFeedTest extends AccessTestCase
{
    private function actingAsRoleManager(): User
    {
        $user = $this->userWithPermissions('roles.view', 'roles.manage');

        $this->actingAsStateful($user);

        return $user;
    }

    public function test_the_feed_lists_role_entries_newest_first_with_their_role_block(): void
    {
        $admin = $this->actingAsRoleManager();
        $admin->givePermissionTo($this->permission('users.view'), $this->permission('users.manage'));
        $this->app['auth']->forgetGuards();

        $role = $this->postJson('/api/access/roles', ['name' => 'ops'])->assertStatus(201)->json('data.role');

        $this->putJson("/api/access/roles/{$role['id']}/permissions", [
            'permission_ids' => [
                $this->permission('roles.view')->getKey(),
            ]
        ])->assertOk();

        $this->patchJson("/api/access/roles/{$role['id']}", ['name' => 'operations'])->assertOk();

        // A user-subject entry, proving the feed carries the role surface only.
        $this->postJson('/api/access/users', [
            'first_name' => 'Someone', 'last_name' => 'Else', 'email' => 'someone@example.com',
        ])->assertStatus(201);

        $entries = $this->getJson('/api/access/roles/audit-logs')->assertOk()->json('data.entries');

        $this->assertSame(
            ['role.renamed', 'role.permissions_synced', 'role.created'],
            array_column($entries, 'action'),
        );

        foreach ($entries as $entry) {
            $this->assertSame([
                'id' => $role['id'], 'name' => 'operations', 'deleted' => false,
            ], $entry['role']);
            $this->assertSame($admin->id, $entry['actor']['id']);
            $this->assertFalse($entry['actor']['restricted']);
            // A reachable actor keeps their IP: the redaction is scoped to withheld identities, not blanket.
            $this->assertNotNull($entry['ip_address']);
        }

        $this->assertSame(
            ['permissions' => []],
            array_column($entries, 'before', 'action')['role.permissions_synced'],
        );
    }

    public function test_a_deleted_roles_history_stays_readable_with_its_snapshot_name(): void
    {
        $this->actingAsRoleManager();

        $role = $this->postJson('/api/access/roles', ['name' => 'ephemeral'])->assertStatus(201)->json('data.role');
        $this->deleteJson("/api/access/roles/{$role['id']}")->assertStatus(204);

        $this->assertNull(config('permission.models.role')::find($role['id']));

        $entries = $this->getJson('/api/access/roles/audit-logs')->assertOk()->json('data.entries');

        $this->assertSame(['role.deleted', 'role.created'], array_column($entries, 'action'));

        // The roles row is gone; the name renders from the deletion entry's snapshot on every entry of its life.
        foreach ($entries as $entry) {
            $this->assertSame([
                'id' => $role['id'], 'name' => 'ephemeral', 'deleted' => true,
            ], $entry['role']);
        }

        $this->assertSame('ephemeral', $entries[0]['before']['name']);
    }

    public function test_the_feed_narrows_to_one_role_with_the_role_id_filter(): void
    {
        $this->actingAsRoleManager();

        $kept = $this->postJson('/api/access/roles', ['name' => 'kept'])->assertStatus(201)->json('data.role');
        $this->postJson('/api/access/roles', ['name' => 'other'])->assertStatus(201);

        $entries = $this->getJson("/api/access/roles/audit-logs?filter[role_id]={$kept['id']}")
            ->assertOk()->json('data.entries');

        $this->assertCount(1, $entries);
        $this->assertSame('kept', $entries[0]['role']['name']);

        // An id that names no role narrows to nothing rather than falling back to the whole surface - including
        // 0, which is a filter like any other and must not be read as "none given".
        $this->assertSame([], $this->getJson('/api/access/roles/audit-logs?filter[role_id]=0')
            ->assertOk()->json('data.entries'));
        $this->assertSame([], $this->getJson('/api/access/roles/audit-logs?filter[role_id]=999999')
            ->assertOk()->json('data.entries'));

        // Omitting the filter is the only way to ask for everything.
        $this->assertCount(2, $this->getJson('/api/access/roles/audit-logs')->assertOk()->json('data.entries'));

        $this->getJson('/api/access/roles/audit-logs?filter[role_id]=not-a-number')->assertStatus(422);
    }

    public function test_the_feed_requires_the_roles_view_capability(): void
    {
        $viewer = $this->userWithPermissions('users.view');
        $this->actingAsStateful($viewer);

        $this->getJson('/api/access/roles/audit-logs')->assertStatus(403);
    }

    public function test_an_out_of_scope_actors_identity_is_restricted_in_the_feed(): void
    {
        // The role's editor is an account the viewer's record scope does not reach: the entry survives,
        // marked restricted, with no name, email or IP - the same redaction the user trail applies.
        // This feed makes the IP matter more than the per-user trail does: it is global, so an unredacted
        // address here would let any roles.view holder harvest every admin they cannot otherwise see.
        UserAllowlistDimension::$visible = [];
        config(['access.dimensions' => [UserAllowlistDimension::class]]);

        $editor = $this->userWithPermissions('roles.view', 'roles.manage');
        $this->actingAsStateful($editor);
        $this->postJson('/api/access/roles', ['name' => 'ops'])->assertStatus(201);

        $viewer = $this->userWithPermissions('roles.view');
        $this->actingAsStateful($viewer);
        $this->app['auth']->forgetGuards();

        UserAllowlistDimension::$visible[$viewer->id] = [$viewer->id];

        $entries = $this->getJson('/api/access/roles/audit-logs')->assertOk()->json('data.entries');

        $this->assertCount(1, $entries);
        $this->assertSame(['restricted' => true], $entries[0]['actor']);
        $this->assertNull($entries[0]['ip_address']);
        $this->assertSame('ops', $entries[0]['role']['name']);
    }
}
