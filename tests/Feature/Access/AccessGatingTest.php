<?php

namespace Tests\Feature\Access;

use App\Models\User;

/**
 * The per-capability gating matrix: each access sub-group is reachable
 * with exactly its own permission, and a capability never implies a
 * sibling ({resource}.manage does not grant {resource}.view).
 */
class AccessGatingTest extends AccessTestCase
{
    private function actingAsHolderOf(string ...$permissions): User
    {
        $user = $this->userWithPermissions(...$permissions);

        $this->actingAsStateful($user);

        return $user;
    }

    public function test_users_view_grants_reads_but_no_mutations_or_role_endpoints(): void
    {
        $this->actingAsHolderOf('users.view');
        $target = $this->createUser();

        $this->getJson('/api/access/users')->assertOk();
        $this->getJson("/api/access/users/{$target->id}")->assertOk();
        $this->getJson("/api/access/users/{$target->id}/sessions")->assertOk();
        $this->getJson('/api/access/users/stats')->assertOk();
        $this->get('/api/access/users/export')->assertOk();

        $this->putJson("/api/access/users/{$target->id}/roles", ['role_ids' => []])->assertStatus(403);
        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/access/users/{$target->id}")->assertStatus(403);
        $this->postJson('/api/access/users', [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com',
        ])->assertStatus(403);

        $this->getJson('/api/access/roles')->assertStatus(403);
        $this->getJson('/api/access/permissions')->assertStatus(403);
    }

    public function test_users_manage_grants_mutations_but_does_not_imply_view(): void
    {
        $this->actingAsHolderOf('users.manage');
        $target = $this->createUser(['first_name' => 'Old']);

        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'New'])->assertOk();
        $this->assertSame('New', $target->fresh()->first_name);
        $this->postJson('/api/access/users', [
            'first_name' => 'Made', 'last_name' => 'ByManager', 'email' => 'made@example.com',
        ])->assertStatus(201);

        $this->getJson('/api/access/users')->assertStatus(403);
        $this->getJson("/api/access/users/{$target->id}")->assertStatus(403);
        $this->getJson('/api/access/users/stats')->assertStatus(403);
        $this->get('/api/access/users/export')->assertStatus(403);
    }

    public function test_roles_view_grants_the_dictionaries_but_no_mutations(): void
    {
        $this->actingAsHolderOf('roles.view');

        $this->getJson('/api/access/roles')->assertOk();
        $this->getJson('/api/access/permissions')->assertOk();

        $this->postJson('/api/access/roles', ['name' => 'auditors'])->assertStatus(403);
        $this->getJson('/api/access/users')->assertStatus(403);
        $this->getJson('/api/access/protectables')->assertStatus(403);
    }

    public function test_roles_manage_grants_role_and_rule_mutations_but_no_user_endpoints(): void
    {
        $this->actingAsHolderOf('roles.manage');

        $created = $this->postJson('/api/access/roles', ['name' => 'auditors'])->assertStatus(201);
        $roleId = $created->json('data.role.id');

        $permission = $this->permission('widgets.special');
        $this->putJson("/api/access/roles/{$roleId}/permissions", ['permission_ids' => [$permission->getKey()]])
            ->assertOk();

        $this->getJson('/api/access/protectables')->assertOk();

        $this->getJson('/api/access/users')->assertStatus(403);
        $this->getJson('/api/access/roles')->assertStatus(403);
    }
}
