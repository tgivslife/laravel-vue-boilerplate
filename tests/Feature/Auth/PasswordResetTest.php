<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-reset limiters key by IP, which is identical for every test in this file;
        // without a flush the counters bleed across tests.
        $this->app['cache']->flush();

        Notification::fake();
    }

    /* ------------------------------------------------------------------ *
     *  Requesting a reset link
     * ------------------------------------------------------------------ */

    public function test_request_for_existing_user_queues_a_notification(): void
    {
        $user = $this->createUser();

        $response = $this->requestReset($user->email);

        $response->assertStatus(202);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_request_matches_an_existing_account_regardless_of_email_case(): void
    {
        // A case-variant spelling must still reach the account: the enumeration-resistant
        // response would otherwise hide that no mail was ever sent.
        $user = $this->createUser(['email' => 'case.test@example.com']);

        $this->requestReset('Case.Test@Example.COM')->assertStatus(202);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_request_for_unknown_email_returns_the_identical_response(): void
    {
        $user = $this->createUser();

        $known = $this->requestReset($user->email);
        $unknown = $this->requestReset('nobody@example.com');

        // Byte-for-byte identical body and status: an attacker probing the endpoint cannot tell which emails have accounts.
        $unknown->assertStatus(202);
        $this->assertSame($known->getContent(), $unknown->getContent());

        Notification::assertCount(1);
    }

    public function test_request_for_deactivated_or_banned_user_sends_nothing(): void
    {
        $inactive = $this->createUser(['is_active' => false]);
        $banned = $this->createUser(['banned_at' => now(), 'ban_reason' => 'test']);

        $this->requestReset($inactive->email)->assertStatus(202);
        $this->requestReset($banned->email)->assertStatus(202);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_request_sends_nothing_when_the_feature_is_disabled(): void
    {
        config(['security.password_reset.enabled' => false]);
        $user = $this->createUser();

        $this->requestReset($user->email)->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_request_requires_a_valid_email(): void
    {
        $this->requestReset('not-an-email')->assertStatus(422);
    }

    public function test_request_is_throttled_per_email_and_ip(): void
    {
        config(['security.password_reset.request_limit.max_attempts' => 2]);
        $user = $this->createUser();

        $this->requestReset($user->email)->assertStatus(202);
        $this->requestReset($user->email)->assertStatus(202);

        $this->requestReset($user->email)->assertStatus(429);
    }

    public function test_emailed_link_targets_the_spa_reset_page_with_token_and_email(): void
    {
        $user = $this->createUser();

        $this->requestReset($user->email)->assertStatus(202);

        $url = $this->capturedResetUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(url('/auth/password/reset'), $url);
        $this->assertIsString($query['token'] ?? null);
        $this->assertSame($user->email, $query['email'] ?? null);
    }

    /* ------------------------------------------------------------------ *
     *  Resetting the password
     * ------------------------------------------------------------------ */

    public function test_valid_token_resets_the_password(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->performReset($user->email, $token)->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('new-sturdy-passphrase', $user->getAttribute('password')));
        $this->assertNotNull($user->getAttribute('remember_token'));
        // The recovery path stamps the change like the settings and admin paths do,
        // so the admin detail view never shows a stale timestamp after a reset.
        $this->assertNotNull($user->password_changed_at);
        Event::assertDispatched(PasswordReset::class);
    }

    public function test_reset_notifies_the_account_owner_of_the_change(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->performReset($user->email, $token)->assertStatus(200);

        Notification::assertSentTo($user, PasswordChangedNotification::class);

        // Credential-lifecycle event: the forgot-password reset is audited like the settings change, with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.password_changed',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_reset_clears_an_admin_imposed_forced_reset(): void
    {
        $user = $this->createUser(['require_password_reset' => true]);
        $token = $this->issueTokenFor($user);

        $this->performReset($user->email, $token)->assertStatus(200);

        $this->assertFalse($user->refresh()->require_password_reset);
    }

    public function test_reset_revokes_every_personal_access_token(): void
    {
        $user = $this->createUser();
        $user->createToken('integration-token');
        $token = $this->issueTokenFor($user);

        $this->performReset($user->email, $token)->assertStatus(200);

        // Recovery means no credential predating it survives - an attacker who minted a token while in control must lose API access too.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_signs_out_every_existing_session(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);
        $sessionId = $this->createOtherSessionFor($user);

        $this->performReset($user->email, $token)->assertStatus(200);

        // A reset is the recovery path: no session may predate it.
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->getKey()]);
        $this->assertFalse($this->sessionExists($sessionId));
    }

    public function test_token_is_single_use(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->performReset($user->email, $token)->assertStatus(200);
        $this->performReset($user->email, $token)->assertStatus(401);
    }

    public function test_wrong_token_is_rejected(): void
    {
        $user = $this->createUser();
        $this->issueTokenFor($user);

        $this->performReset($user->email, 'wrong-token')->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->performReset($user->email, $token)->assertStatus(401);
    }

    public function test_deactivated_user_cannot_reset_and_gets_the_same_invalid_response(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $user->forceFill(['is_active' => false])->save();

        $wrongToken = $this->performReset($user->email, 'wrong-token');
        $deactivated = $this->performReset($user->email, $token);

        // Same status, title and detail (the `instance` request id always differs): the endpoint is not an account-state oracle.
        $deactivated->assertStatus(401);
        $this->assertSame($wrongToken->json('title'), $deactivated->json('title'));
        $this->assertSame($wrongToken->json('detail'), $deactivated->json('detail'));
    }

    public function test_reset_is_rejected_when_the_feature_is_disabled(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        config(['security.password_reset.enabled' => false]);

        $this->performReset($user->email, $token)->assertStatus(401);
    }

    public function test_reset_validates_password_confirmation_and_length(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->performReset($user->email, $token, password: 'new-sturdy-passphrase', confirmation: 'different')
            ->assertStatus(422);
        $this->performReset($user->email, $token, password: 'short', confirmation: 'short')
            ->assertStatus(422);
    }

    public function test_reset_attempts_are_throttled_per_ip(): void
    {
        config(['security.password_reset.attempt_limit.max_attempts' => 2]);
        $user = $this->createUser();
        $this->issueTokenFor($user);

        $this->performReset($user->email, 'wrong-token')->assertStatus(401);
        $this->performReset($user->email, 'another-wrong-token')->assertStatus(401);

        $this->performReset($user->email, 'third-wrong-token')->assertStatus(429);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * Request a password-reset link as the SPA would (stateful frontend origin).
     */
    private function requestReset(string $email): TestResponse
    {
        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/password/forgot', ['email' => $email]);
    }

    /**
     * Submit the reset form as the SPA would.
     */
    private function performReset(
        string $email,
        string $token,
        string $password = 'new-sturdy-passphrase',
        ?string $confirmation = null,
    ): TestResponse {
        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/password/reset', [
                'token' => $token,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation ?? $password,
            ]);
    }

    /**
     * Issue a real token for the user through the HTTP endpoint and return the plaintext extracted from the captured notification URL.
     */
    private function issueTokenFor(User $user): string
    {
        $this->requestReset($user->email)->assertStatus(202);

        parse_str((string) parse_url($this->capturedResetUrl($user), PHP_URL_QUERY), $query);

        $this->assertIsString($query['token'] ?? null);

        return $query['token'];
    }

    /**
     * Extract the reset URL from the (faked) queued notification.
     */
    private function capturedResetUrl(User $user): string
    {
        $url = null;

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$url, $user): bool {
                $url = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($url);

        return $url;
    }
}
