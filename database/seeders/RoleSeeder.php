<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the baseline roles in the configured guard.
 *
 * The ladder: super-admin (break-glass bypass, protected in the admin API) and admin (every capability). Further roles
 * are composed at runtime through the admin UI. Grants are synced from the seeded vocabulary, so resources added to
 * config/access.php are picked up on reseed without touching this file.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // The registrar caches lookups eagerly; without a flush it would
        // miss the permissions PermissionSeeder just created.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('access.guard');
        $roleModel = config('permission.models.role');
        $permissionModel = config('permission.models.permission');

        $allPermissions = $permissionModel::where('guard_name', $guard)->get();

        // Bypasses every check via Gate::before, so attaching permissions
        // would only be dead data - deliberately left empty.
        $roleModel::findOrCreate(config('access.super_admin_role'), $guard);

        // The everyday administrator: explicit, auditable grants across the
        // whole vocabulary, so admins reshape users' roles and permissions
        // through the UI.
        $roleModel::findOrCreate('admin', $guard)
            ->syncPermissions($allPermissions);
    }
}
