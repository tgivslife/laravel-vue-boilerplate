<?php

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_denied(): void
    {
        $this->get('/horizon')->assertForbidden();
    }

    public function test_users_without_the_capability_are_denied(): void
    {
        $this->actingAs($this->createUser());

        $this->get('/horizon')->assertForbidden();
    }

    public function test_settings_managers_can_view_the_dashboard(): void
    {
        $user = $this->createUser();
        $user->givePermissionTo(
            config('permission.models.permission')::findOrCreate('settings.manage', config('access.guard'))
        );

        $this->actingAs($user);

        $this->get('/horizon')->assertOk();
    }

    public function test_super_admins_can_view_the_dashboard(): void
    {
        $user = $this->createUser();
        $user->assignRole(
            config('permission.models.role')::findOrCreate(config('access.super_admin_role'), config('access.guard'))
        );

        $this->actingAs($user);

        $this->get('/horizon')->assertOk();
    }

    public function test_the_local_environment_gets_no_bypass(): void
    {
        // The stock provider waves everyone through on local; ours enforces
        // the gate everywhere so permissions can be exercised in local dev.
        $this->app['env'] = 'local';

        $this->actingAs($this->createUser());

        $this->get('/horizon')->assertForbidden();
    }
}
