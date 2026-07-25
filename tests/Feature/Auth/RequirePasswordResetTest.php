<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys by user id, which repeats
        // across tests under RefreshDatabase; without a flush the counters
        // bleed between tests.
        $this->app['cache']->flush();
    }

    public function test_flagged_user_can_still_sign_in(): void
    {
        $user = $this->createUser(['require_password_reset' => true]);

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(200);
    }

    public function test_flagged_user_sees_the_flag_on_their_profile(): void
    {
        $user = $this->createUser(['require_password_reset' => true]);

        $this->actingAsStateful($user)
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('data.require_password_reset', true);
    }

    public function test_flagged_user_is_blocked_from_the_gated_api(): void
    {
        $user = $this->createUser(['require_password_reset' => true]);

        $this->actingAsStateful($user)
            ->getJson('/api/sessions')
            ->assertStatus(403)
            ->assertJsonPath('detail', __('api.auth.password_reset_required'));
    }

    public function test_flagged_user_can_still_log_out(): void
    {
        $user = $this->createUser(['require_password_reset' => true]);

        $this->actingAsStateful($user)
            ->postJson('/api/logout')
            ->assertSuccessful();
    }

    public function test_changing_the_password_clears_the_flag_and_unblocks_the_api(): void
    {
        $user = $this->createUser(['require_password_reset' => true]);

        $this->actingAsStateful($user);

        $this->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(200);

        $this->assertFalse($user->refresh()->require_password_reset);
        $this->getJson('/api/sessions')->assertStatus(200);
    }

    public function test_unflagged_user_is_unaffected(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->getJson('/api/sessions')
            ->assertStatus(200);
    }
}
