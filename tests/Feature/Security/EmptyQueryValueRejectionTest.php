<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\Feature\Access\AccessTestCase;

/**
 * Present-but-empty query values must 422 at validation on every index surface: `sometimes` alone
 * skips non-implicit rules for `?filter[x]=`, and the "" that passes through reaches a typed bind -
 * a driver error on PostgreSQL (bigint/date columns) or a division by zero (per_page) - while
 * SQLite, the test driver, tolerates the comparisons. The `filled` rule is the contract under test.
 */
class EmptyQueryValueRejectionTest extends AccessTestCase
{
    private function superAdmin(): User
    {
        $admin = $this->createUser();
        $role = config('permission.models.role')::findOrCreate(config('access.super_admin_role'),
            config('access.guard'));
        $admin->assignRole($role);

        return $admin;
    }

    public function test_present_but_empty_query_values_are_rejected_across_the_index_surfaces(): void
    {
        $this->actingAsStateful($this->superAdmin());

        $probes = [
            '/api/access/users?filter[role_id]=' => 'filter.role_id',
            '/api/access/users?per_page=' => 'per_page',
            '/api/access/roles?filter[search]=' => 'filter.search',
            '/api/access/roles/audit-logs?filter[role_id]=' => 'filter.role_id',
            '/api/access/permissions?per_page=' => 'per_page',
            '/api/authentication-log?date=' => 'date',
        ];

        foreach ($probes as $url => $field) {
            $this->getJson($url)
                ->assertStatus(422)
                ->assertJsonPath('errors.0.name', $field);
        }
    }
}
