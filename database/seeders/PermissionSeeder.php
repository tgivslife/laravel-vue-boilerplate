<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the capability vocabulary from config/access.php.
 *
 * Permissions are code-defined on purpose: the application is written
 * against these names, so they are seeded (idempotently) rather than
 * managed through the UI. Roles are the runtime-composed layer.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('access.guard');
        $permissionModel = config('permission.models.permission');

        $names = collect(config('access.resources', []))
            ->flatMap(static fn(array $verbs, string $resource) => collect($verbs)
                ->map(static fn(string $verb): string => "{$resource}.{$verb}"))
            ->merge(config('access.standalone_permissions', []))
            ->merge(config('access.lockout_permissions', []))
            ->unique()
            ->values();

        foreach ($names as $name) {
            $permissionModel::findOrCreate($name, $guard);
        }
    }
}
