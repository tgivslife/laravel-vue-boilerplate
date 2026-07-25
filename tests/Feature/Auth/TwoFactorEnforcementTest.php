<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys per user+IP, identical for
        // every test in this file; without a flush the counters bleed across tests.
        $this->app['cache']->flush();
    }

    public function test_a_mandated_account_is_trapped_until_it_enrolls(): void
    {
        $user = $this->createUser(['two_factor_required' => true]);
        $this->actingAsStateful($user);

        $response = $this->getJson('/api/sessions');
        $response->assertStatus(403);
        $response->assertJsonPath('title', __('api.auth.titles.two_factor_enrollment_required'));

        // The way out stays reachable: the enrollment surface is routed outside the gate.
        $this->postJson('/api/two-factor', ['password' => 'password'])->assertOk();
    }

    public function test_the_user_endpoint_reports_the_pending_mandate(): void
    {
        $user = $this->createUser(['two_factor_required' => true]);
        $this->actingAsStateful($user);

        $this->getJson('/api/user')->assertJsonPath('data.two_factor_enrollment_required', true);
    }

    public function test_enrolling_satisfies_the_mandate(): void
    {
        $user = $this->createUser(['two_factor_required' => true]);
        $this->actingAsStateful($user);

        $engine = $this->app->make(Google2FA::class);
        $secret = $this->postJson('/api/two-factor', ['password' => 'password'])->json('data.secret');
        $this->postJson('/api/two-factor/confirm', ['code' => $engine->getCurrentOtp($secret)])->assertOk();

        $this->getJson('/api/sessions')->assertOk();
        $this->getJson('/api/user')->assertJsonPath('data.two_factor_enrollment_required', false);
    }

    public function test_unflagged_accounts_are_unaffected(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $this->getJson('/api/sessions')->assertOk();
        $this->getJson('/api/user')->assertJsonPath('data.two_factor_enrollment_required', false);
    }

    public function test_the_kill_switch_lifts_the_mandate(): void
    {
        config(['security.two_factor.enabled' => false]);

        $user = $this->createUser(['two_factor_required' => true]);
        $this->actingAsStateful($user);

        $this->getJson('/api/sessions')->assertOk();
    }
}
