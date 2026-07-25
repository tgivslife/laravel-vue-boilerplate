<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_without_origin_or_referer_is_rejected(): void
    {
        // Password login is session-only: a request Sanctum does not
        // recognize as first-party has no session to authenticate into,
        // and no token is issued as a fallback.
        $user = $this->createUser();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('title', __('api.auth.titles.session_unavailable'));

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_not_found_when_password_login_is_disabled(): void
    {
        config(['security.password_login.enabled' => false]);
        $user = $this->createUser();

        $this->loginAs($user, 'password')->assertStatus(404);

        $this->assertGuest();
    }

    public function test_login_with_matching_referer_authenticates_the_user(): void
    {
        $user = $this->createUser();

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(200);
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_response_contains_the_user_resource(): void
    {
        $user = $this->createUser();

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.first_name', $user->first_name);
        $response->assertJsonPath('data.last_name', $user->last_name);
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonPath('data.email_verified', true);
        $response->assertJsonMissingPath('data.password');
    }

    public function test_login_with_unknown_email_returns_invalid_credentials_error(): void
    {
        $response = $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'password',
            ]);

        $response->assertStatus(401);
        $response->assertJsonPath('title', 'Invalid Credentials');

        $this->assertGuest();
    }

    public function test_login_with_incorrect_password_returns_invalid_credentials_error(): void
    {
        $user = $this->createUser();

        $response = $this->loginAs($user, 'wrong-password');

        $response->assertStatus(401);
        $response->assertJsonPath('title', 'Invalid Credentials');

        $this->assertGuest();
    }

    public function test_already_authenticated_user_cannot_login_again(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(409);
        $response->assertJsonPath('title', 'Already Authenticated');
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'email');
        $response->assertJsonPath('errors.1.name', 'password');

        $this->assertGuest();
    }

    public function test_login_requires_a_valid_email_address(): void
    {
        $response = $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => 'not-an-email',
                'password' => 'password',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'email');
    }

    public function test_remember_me_persists_a_remember_token_for_the_user(): void
    {
        $user = $this->createUser();

        $response = $this->loginAs($user, 'password', remember: true);

        $response->assertStatus(200);
        $this->assertNotNull($user->refresh()->remember_token);
    }

    public function test_login_without_remember_me_does_not_persist_a_remember_token(): void
    {
        $user = $this->createUser();

        $response = $this->loginAs($user, 'password', remember: false);

        $response->assertStatus(200);
        $this->assertNull($user->refresh()->remember_token);
    }

    public function test_login_locks_out_after_too_many_failed_attempts(): void
    {
        config(['security.lockout.enabled' => true, 'security.lockout.max_attempts' => 5]);
        $user = $this->createUser();

        // The configured maximum of failed attempts still return 401.
        for ($i = 0; $i < 5; $i++) {
            $this->loginAs($user, 'wrong-password')->assertStatus(401);
        }

        // The next attempt is locked out before credentials are even checked.
        $response = $this->loginAs($user, 'wrong-password');

        $response->assertStatus(423);
        $response->assertJsonPath('title', __('api.auth.titles.account_locked'));
        $response->assertHeader('Retry-After');
    }

    public function test_correct_password_is_rejected_once_locked_out(): void
    {
        config(['security.lockout.enabled' => true, 'security.lockout.max_attempts' => 5]);
        $user = $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->loginAs($user, 'wrong-password')->assertStatus(401);
        }

        // Even the right password cannot get through while the lock is active.
        $this->loginAs($user, 'password')->assertStatus(423);
        $this->assertGuest();
    }

    public function test_successful_login_clears_the_failed_attempt_counter(): void
    {
        config(['security.lockout.enabled' => true, 'security.lockout.max_attempts' => 5]);
        $user = $this->createUser();

        // Four failures, then a success resets the counter...
        for ($i = 0; $i < 4; $i++) {
            $this->loginAs($user, 'wrong-password')->assertStatus(401);
        }
        $this->loginAs($user, 'password')->assertStatus(200);

        // ...log out again so later attempts are not "already authenticated" (409).
        // The test kernel memoizes the resolved user across requests; drop it
        // so the next request re-resolves from the session, as in production.
        $this->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        // ...and four more failures still do not trip the lockout.
        for ($i = 0; $i < 4; $i++) {
            $this->loginAs($user, 'wrong-password')->assertStatus(401);
        }
    }

    public function test_password_login_is_rejected_for_passwordless_users(): void
    {
        // Magic-link-only accounts have a null password. Auth::attempt() must
        // fail closed for them - with the same error as any wrong password,
        // so the response does not reveal that the account is passwordless.
        $user = $this->createUser(['password' => null]);

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(401);
        $response->assertJsonPath('title', __('api.auth.titles.invalid_credentials'));

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_login_even_with_valid_credentials(): void
    {
        $user = $this->createUser(['is_active' => false]);

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(403);
        $response->assertJsonPath('title', __('api.auth.titles.account_deactivated'));

        $this->assertGuest();
    }

    public function test_banned_user_cannot_login_even_with_valid_credentials(): void
    {
        $user = $this->createUser(['banned_at' => now(), 'ban_reason' => 'test']);

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(403);
        $response->assertJsonPath('title', __('api.auth.titles.account_deactivated'));

        $this->assertGuest();
    }

    public function test_deactivated_outcome_requires_valid_credentials_first(): void
    {
        // Wrong password on a deactivated account must read as plain invalid
        // credentials - account state is only revealed after authentication.
        $user = $this->createUser(['is_active' => false]);

        $response = $this->loginAs($user, 'wrong-password');

        $response->assertStatus(401);
        $response->assertJsonPath('title', __('api.auth.titles.invalid_credentials'));
    }

    public function test_soft_deleted_user_cannot_login(): void
    {
        $user = $this->createUser();
        $user->delete();

        $response = $this->loginAs($user, 'password');

        $response->assertStatus(401);
        $response->assertJsonPath('title', __('api.auth.titles.invalid_credentials'));

        $this->assertGuest();
    }

    public function test_array_email_is_rejected_by_validation_not_a_server_error(): void
    {
        // Regression: the login throttle previously ran before validation and
        // crashed (500) on a non-string email. It now validates first.
        $response = $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => ['array'],
                'password' => 'password',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'email');
    }

    /**
     * Submit a login request as if it came from the configured frontend
     * origin, so it passes Sanctum's stateful "fromFrontend" check.
     */
    private function loginAs(User $user, string $password, bool $remember = false): TestResponse
    {
        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => $password,
                'remember' => $remember,
            ]);
    }
}
