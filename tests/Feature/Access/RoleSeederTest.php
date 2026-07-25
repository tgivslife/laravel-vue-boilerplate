<?php

namespace Tests\Feature\Access;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_roles_are_seeded_with_the_expected_grants(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $guard = config('access.guard');
        $roleModel = config('permission.models.role');

        // The vocabulary is exactly the per-resource capabilities.
        $this->assertSame(
            ['roles.manage', 'roles.view', 'settings.manage', 'users.impersonate', 'users.manage', 'users.view'],
            config('permission.models.permission')::where('guard_name', $guard)
                ->pluck('name')->sort()->values()->all()
        );

        // Admin holds the whole vocabulary.
        $admin = $roleModel::findByName('admin', $guard);
        foreach (['users.view', 'users.manage', 'users.impersonate', 'roles.view', 'roles.manage', 'settings.manage'] as $name) {
            $this->assertTrue($admin->hasPermissionTo($name));
        }

        // The bypass role deliberately carries nothing (Gate::before).
        $superAdmin = $roleModel::findByName(config('access.super_admin_role'), $guard);
        $this->assertCount(0, $superAdmin->permissions);

        // The ladder is two rungs: no manager or default user role.
        $this->assertSame(
            ['admin', config('access.super_admin_role')],
            $roleModel::where('guard_name', $guard)->pluck('name')->sort()->values()->all()
        );
    }
}
