<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_cannot_access_user_endpoint(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertUnauthorized();
    }

    public function test_token_authenticated_user_can_retrieve_their_details(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateless($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.first_name', $user->first_name);
        $response->assertJsonPath('data.last_name', $user->last_name);
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonPath('data.email_verified', true);
        $response->assertJsonMissingPath('data.password');
    }

    public function test_web_authenticated_user_can_retrieve_their_details(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateful($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', $user->email);
    }

    public function test_user_banned_mid_session_is_cut_off(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $this->getJson('/api/user')->assertOk();

        $user->forceFill(['banned_at' => now(), 'ban_reason' => 'test'])->save();

        // The test kernel memoizes the resolved user across requests; drop it
        // so the next request re-resolves from the session, as in production.
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/user');

        $response->assertStatus(403);
        $response->assertJsonPath('title', __('api.auth.titles.account_deactivated'));
    }

    public function test_token_user_deactivated_mid_session_is_cut_off_and_token_revoked(): void
    {
        $user = $this->createUser();
        $this->actingAsStateless($user);

        $this->getJson('/api/user')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        // Same guard-memoization reset as the stateful variant above.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user')->assertStatus(403);

        // The middleware revokes the credential, so access does not silently
        // resume if the account is reactivated with an old token in the wild.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_response_contains_expected_envelope(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateless($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonStructure(['status', 'message', 'data']);
        $response->assertJsonPath('status', 200);
        $response->assertJsonMissingPath('meta');
    }
}
