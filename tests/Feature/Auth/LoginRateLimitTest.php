<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The per-IP volume ceiling on the credential doors (throttle:login), which complements the per-credential
 * email+IP failure lockout. Its job is the one the lockout cannot do: bound password spraying, where one
 * password is tried across many emails so no single email's failure bucket ever reaches the lockout threshold.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function loginFrom(string $ip, string $email, string $password = 'wrong-password'): TestResponse
    {
        return $this
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    public function test_password_spraying_is_bounded_by_the_per_ip_login_limit(): void
    {
        // A distinct email each time, so the email+IP lockout never trips (every bucket stays at 1);
        // only the per-IP volume limiter can catch this.
        config(['security.login.request_limit.max_attempts' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->loginFrom('203.0.113.10', "spray{$i}@example.test")
                ->assertStatus(401)
                ->assertJsonPath('title', __('api.auth.titles.invalid_credentials'));
        }

        // The fourth request from the same IP is refused before the controller runs.
        $this->loginFrom('203.0.113.10', 'spray4@example.test')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_the_login_ip_limit_is_keyed_per_ip(): void
    {
        config(['security.login.request_limit.max_attempts' => 2]);

        $this->loginFrom('203.0.113.20', 'a@example.test')->assertStatus(401);
        $this->loginFrom('203.0.113.20', 'b@example.test')->assertStatus(401);
        $this->loginFrom('203.0.113.20', 'c@example.test')->assertStatus(429);

        // A different IP has its own bucket and is unaffected.
        $this->loginFrom('203.0.113.21', 'd@example.test')->assertStatus(401);
    }

    public function test_a_successful_login_within_the_limit_is_not_blocked(): void
    {
        config(['security.login.request_limit.max_attempts' => 5]);
        $user = $this->createUser();

        $this->loginFrom('203.0.113.30', $user->email, 'password')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_the_two_factor_challenge_shares_the_login_ip_limit(): void
    {
        // Throttling runs before the controller, so the cap applies regardless of the challenge's own
        // outcome: the first requests are consumed (no pending challenge), then the limit takes over.
        config(['security.login.request_limit.max_attempts' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
                ->postJson('/api/two-factor/challenge', ['code' => '123456']);
            $this->assertNotSame(429, $response->status());
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->postJson('/api/two-factor/challenge', ['code' => '123456'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
