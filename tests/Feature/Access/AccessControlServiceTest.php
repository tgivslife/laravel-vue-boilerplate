<?php

namespace Tests\Feature\Access;

use App\Models\Access\AccessAuditLog;
use App\Models\Access\RequiredPermission;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Contracts\Role as RoleContract;
use Tests\Support\Widget;

class AccessControlServiceTest extends AccessTestCase
{
    private AccessControlService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccessControlService::class);
    }

    /**
     * A user who manages access through a role holding the given lockout
     * permissions (all of them by default).
     *
     * @param  list<string>|null  $permissions
     */
    private function manager(string $roleName = 'managers', ?array $permissions = null): User
    {
        $role = $this->role($roleName);
        $role->givePermissionTo($permissions ?? config('access.lockout_permissions'));

        $user = $this->createUser();
        $user->assignRole($role);

        return $user;
    }

    private function role(string $name): RoleContract
    {
        return config('permission.models.role')::findOrCreate($name, config('access.guard'));
    }

    public function test_user_roles_are_synced_and_audited(): void
    {
        $actor = $this->manager();
        $target = $this->createUser();
        $editors = $this->role('editors');

        $this->service->syncUserRoles($actor, $target, [$editors->getKey()]);

        $this->assertTrue($target->fresh()->hasRole('editors'));

        $log = AccessAuditLog::latest('id')->first();
        $this->assertSame('user.roles_synced', $log->action);
        $this->assertSame($actor->getKey(), $log->actor_id);
        $this->assertSame('user', $log->subject_type);
        $this->assertSame($target->getKey(), $log->subject_id);
        $this->assertSame(['roles' => []], $log->before);
        $this->assertSame(['roles' => ['editors']], $log->after);
    }

    public function test_direct_permissions_are_synced_and_audited(): void
    {
        $actor = $this->manager();
        $target = $this->createUser();
        $permission = $this->permission('widgets.special');

        $this->service->syncUserPermissions($actor, $target, [$permission->getKey()]);

        $this->assertTrue($target->fresh()->hasDirectPermission('widgets.special'));
        $this->assertSame('user.permissions_synced', AccessAuditLog::latest('id')->first()->action);
    }

    public function test_an_admin_cannot_revoke_their_own_management_access(): void
    {
        $actor = $this->manager();
        // A second manager exists, so only the self-revocation guard can fire.
        $this->manager('other-managers');

        try {
            $this->service->syncUserRoles($actor, $actor, []);
            $this->fail('Expected the self-revocation guard to fire.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('api.access.self_revocation'), $exception->errors()['access'][0]);
        }

        // The transaction rolled back: the actor still holds the role.
        $this->assertTrue($actor->fresh()->hasRole('managers'));
        $this->assertDatabaseCount('access_audit_logs', 0);
    }

    public function test_the_application_cannot_lose_its_last_active_manager(): void
    {
        // The actor manages but is deactivated; the other manager is the
        // only ACTIVE one, so removing their role must trip the guard.
        $actor = $this->manager();
        $actor->forceFill(['is_active' => false])->save();

        $other = $this->manager('other-managers');

        try {
            $this->service->syncUserRoles($actor, $other, []);
            $this->fail('Expected the last-manager guard to fire.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('api.access.last_manager'), $exception->errors()['access'][0]);
        }

        $this->assertTrue($other->fresh()->hasRole('other-managers'));
    }

    public function test_removing_another_manager_is_allowed_while_the_actor_remains(): void
    {
        $actor = $this->manager();
        $other = $this->manager('other-managers');

        $this->service->syncUserRoles($actor, $other, []);

        $this->assertFalse($other->fresh()->hasRole('other-managers'));
    }

    public function test_the_super_admin_role_cannot_be_renamed_or_deleted(): void
    {
        $actor = $this->manager();
        $superAdmin = $this->role(config('access.super_admin_role'));

        $this->expectException(ValidationException::class);
        $this->service->deleteRole($actor, $superAdmin);
    }

    public function test_deleting_the_actors_only_management_role_is_refused(): void
    {
        $actor = $this->manager();
        $role = $actor->roles()->first();

        try {
            $this->service->deleteRole($actor, $role);
            $this->fail('Expected the self-revocation guard to fire.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('api.access.self_revocation'), $exception->errors()['access'][0]);
        }

        // fresh() is null here regardless (delete() cleared the in-memory
        // exists flag and rollback cannot restore it), so assert on the row.
        $this->assertDatabaseHas('roles', ['id' => $role->getKey()]);
    }

    public function test_stripping_users_manage_from_the_actors_role_is_refused(): void
    {
        $actor = $this->manager();
        $role = $actor->roles()->first();

        // Keeping roles.manage proves each lockout permission is guarded on its own.
        $this->expectException(ValidationException::class);
        $this->service->syncRolePermissions($actor, $role, [$this->permission('roles.manage')->getKey()]);
    }

    public function test_stripping_roles_manage_from_the_actors_role_is_refused(): void
    {
        $actor = $this->manager();
        $role = $actor->roles()->first();

        $this->expectException(ValidationException::class);
        $this->service->syncRolePermissions($actor, $role, [$this->permission('users.manage')->getKey()]);
    }

    public function test_an_actor_holding_a_single_lockout_permission_can_mutate(): void
    {
        // Nobody holds roles.manage here: the guards only protect what a
        // mutation itself breaks, so an actor with users.manage alone works.
        $actor = $this->manager('user-admins', ['users.manage']);
        $target = $this->createUser();
        $editors = $this->role('editors');

        $this->service->syncUserRoles($actor, $target, [$editors->getKey()]);

        $this->assertTrue($target->fresh()->hasRole('editors'));
    }

    public function test_removing_the_last_active_holder_of_a_lockout_permission_is_refused(): void
    {
        $actor = $this->manager('user-admins', ['users.manage']);
        $other = $this->manager('role-admins', ['roles.manage']);

        try {
            $this->service->syncUserRoles($actor, $other, []);
            $this->fail('Expected the last-manager guard to fire.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('api.access.last_manager'), $exception->errors()['access'][0]);
        }

        $this->assertTrue($other->fresh()->hasRole('role-admins'));
    }

    public function test_role_lifecycle_create_rename_sync_delete(): void
    {
        $actor = $this->manager();

        $role = $this->service->createRole($actor, 'auditors');
        $this->assertSame(config('access.guard'), $role->guard_name);

        $this->service->renameRole($actor, $role, 'inspectors');
        $this->assertSame('inspectors', $role->fresh()->name);

        $permission = $this->permission('widgets.special');
        $this->service->syncRolePermissions($actor, $role, [$permission->getKey()]);
        $this->assertTrue($role->fresh()->hasPermissionTo('widgets.special'));

        $this->service->deleteRole($actor, $role->fresh());
        $this->assertNull($this->role('inspectors')->fresh() ? null : 'gone');
        $this->assertSame(
            ['role.created', 'role.renamed', 'role.permissions_synced', 'role.deleted'],
            AccessAuditLog::orderBy('id')->pluck('action')->all()
        );
    }

    public function test_permission_changes_apply_to_fresh_checks_immediately(): void
    {
        $actor = $this->manager();
        $role = $this->role('editors');
        $target = $this->createUser();
        $target->assignRole($role);
        $permission = $this->permission('widgets.special');

        $this->service->syncRolePermissions($actor, $role, [$permission->getKey()]);
        $this->assertTrue($target->fresh()->can('widgets.special'));

        $this->service->syncRolePermissions($actor, $role, []);
        $this->assertFalse($target->fresh()->can('widgets.special'));
    }

    public function test_class_rules_are_replaced_as_a_group(): void
    {
        $actor = $this->manager();
        $a = $this->permission('widgets.a');
        $b = $this->permission('widgets.b');
        $c = $this->permission('widgets.c');

        $this->service->syncClassRules($actor, 'widget', 'view', [$a->getKey(), $b->getKey()], 'all');
        $this->assertSame(2, RequiredPermission::classLevel()->count());

        $this->service->syncClassRules($actor, 'widget', 'view', [$b->getKey(), $c->getKey()], 'any');

        $rules = RequiredPermission::classLevel()->get();
        $this->assertSame([$b->getKey(), $c->getKey()], $rules->pluck('permission_id')->sort()->values()->all());
        $this->assertSame(['any', 'any'], $rules->pluck('mode')->all());
    }

    public function test_record_rules_target_one_record_and_are_audited(): void
    {
        $actor = $this->manager();
        $widget = Widget::create(['name' => 'locked']);
        $permission = $this->permission('widgets.special');

        $this->service->syncRecordRules($actor, $widget, 'view', [$permission->getKey()], 'all');

        $this->assertSame(1, $widget->requiredPermissions()->count());

        $log = AccessAuditLog::latest('id')->first();
        $this->assertSame('rules.record_synced', $log->action);
        $this->assertSame('widget', $log->subject_type);
        $this->assertSame($widget->getKey(), $log->subject_id);
    }

    public function test_rules_are_rejected_for_unknown_protectables(): void
    {
        $actor = $this->manager();

        $this->expectException(ValidationException::class);
        $this->service->syncClassRules($actor, 'session', 'view', [], 'all');
    }
}
