<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\SessionRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Timebox;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingTimebox;
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

    public function test_request_with_a_non_scalar_email_is_a_validation_error_not_a_500(): void
    {
        // The forgot limiter keys on `email` straight from the unvalidated request; an array value would throw
        // "Array to string conversion" inside the throttle middleware (a 500 with warnings promoted to exceptions)
        // before validation could 422 the shape. RateLimitServiceProvider collapses non-scalars to an empty key.
        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/password/forgot', ['email' => ['array@example.com']])
            ->assertStatus(422);
    }

    public function test_request_is_throttled_per_email_and_ip(): void
    {
        config(['security.password_reset.request_limit.max_attempts' => 2]);
        $user = $this->createUser();

        $this->requestReset($user->email)->assertStatus(202);
        $this->requestReset($user->email)->assertStatus(202);

        $this->requestReset($user->email)->assertStatus(429);
    }

    /**
     * Every branch of the send decision runs inside the service's own timebox, once, with one floor: unknown,
     * deactivated, eligible, and eligible but throttled by the broker. The broker's box covers only the branches
     * that reach it, so a branch outside the service's would answer in a fraction of the time. The disabled switch
     * is global state, not account state, and answers ahead of the box.
     */
    public function test_every_send_branch_runs_inside_the_same_timebox(): void
    {
        config(['security.password_reset.request_limit.max_attempts' => 20]);
        $timebox = $this->recordingTimebox();
        $eligible = $this->createUser();
        $inactive = $this->createUser(['is_active' => false]);

        $this->requestReset('nobody@example.com')->assertStatus(202);
        $this->requestReset($inactive->email)->assertStatus(202);
        $this->requestReset($eligible->email)->assertStatus(202);
        $this->requestReset($eligible->email)->assertStatus(202);

        $this->assertSame([500_000, 500_000, 500_000, 500_000], $timebox->floors);
        Notification::assertSentToTimes($eligible, ResetPasswordNotification::class, 1);

        config(['security.password_reset.enabled' => false]);
        $this->requestReset($eligible->email)->assertStatus(202);
        $this->assertCount(4, $timebox->floors);
    }

    /**
     * Every branch of the reset decision runs inside the same box too: unknown email, deactivated account, wrong,
     * expired and valid tokens. The broker returns early from its own box on success; the service's box does not.
     */
    public function test_every_reset_branch_runs_inside_the_same_timebox(): void
    {
        $user = $this->createUser();
        $inactive = $this->createUser(['is_active' => false]);
        $token = $this->issueTokenFor($user);
        $expiredUser = $this->createUser();
        $expiredToken = $this->issueTokenFor($expiredUser);
        $timebox = $this->recordingTimebox();

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();
        $this->performReset($expiredUser->email, $expiredToken)->assertStatus(401);
        $this->travelBack();

        $this->performReset('nobody@example.com', 'any-token')->assertStatus(401);
        $this->performReset($inactive->email, 'any-token')->assertStatus(401);
        $this->performReset($user->email, 'wrong-token')->assertStatus(401);
        $this->performReset($user->email, $token)->assertStatus(200);

        $this->assertSame(array_fill(0, 5, 500_000), $timebox->floors);

        config(['security.password_reset.enabled' => false]);
        $this->performReset($user->email, $token)->assertStatus(401);
        $this->assertCount(5, $timebox->floors);
    }

    /**
     * The floor comes from config, since it has to clear the token-hash cost on the deployment's hardware.
     */
    public function test_the_decision_floor_comes_from_config(): void
    {
        config(['security.auth_decision_floor_ms' => 123]);
        $timebox = new RecordingTimebox;
        $this->app->instance(
            PasswordResetService::class,
            new PasswordResetService(app(SessionRegistry::class), $timebox),
        );

        $this->requestReset('nobody@example.com')->assertStatus(202);

        $this->assertSame([123_000], $timebox->floors);
    }

    /**
     * The floor is real: on a real timebox, an unknown address and an eligible one both take at least the floor. A
     * lower bound only, since the two durations cannot be compared reliably under a loaded runner; that the same box
     * wraps every branch is the recording timebox's job above.
     */
    public function test_an_unknown_and_an_eligible_address_both_take_the_floor(): void
    {
        $floorMs = 250;
        $service = new PasswordResetService(app(SessionRegistry::class), new Timebox, $floorMs * 1000);
        $user = $this->createUser();

        $unknown = $this->millisecondsTaken(fn() => $service->sendResetLink('nobody@example.com'));
        $eligible = $this->millisecondsTaken(fn() => $service->sendResetLink($user->email));

        // A few milliseconds of slack: the box rounds its remainder down and usleep() may wake a little early.
        $this->assertGreaterThanOrEqual($floorMs - 10, $unknown);
        $this->assertGreaterThanOrEqual($floorMs - 10, $eligible);
    }

    /**
     * The device name is resolved when the mail renders, not while the request runs: the user-agent parse is what
     * gave the eligible branch its extra hundred milliseconds.
     */
    public function test_the_device_name_is_resolved_when_the_mail_renders_not_during_the_request(): void
    {
        $user = $this->createUser();
        // Unique per test: Device memoizes resolved names for the process, which would hide the cache write.
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.'.random_int(1,
                999_999).'.0 Safari/537.36';
        $cacheKey = 'device-name:'.hash('sha256', $userAgent);

        $this->withHeader('User-Agent', $userAgent)->requestReset($user->email)->assertStatus(202);

        $this->assertFalse(Cache::has($cacheKey), 'The request parsed the user agent.');

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            static fn(ResetPasswordNotification $notification): bool => str_contains(
                (string) $notification->toMail($user)->viewData['deviceName'], 'Chrome'
            ),
        );
        $this->assertTrue(Cache::has($cacheKey));
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
        $this->assertSame(0, $this->liveSessionCount($user));
        $this->assertSessionRevoked($sessionId);
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
     * Swap the service the endpoints resolve for one on a recording timebox, and hand the box back.
     */
    private function recordingTimebox(): RecordingTimebox
    {
        $timebox = new RecordingTimebox;

        $this->app->instance(
            PasswordResetService::class,
            new PasswordResetService(app(SessionRegistry::class), $timebox, 500_000),
        );

        return $timebox;
    }

    /**
     * @param  callable(): void  $callback
     */
    private function millisecondsTaken(callable $callback): float
    {
        $start = hrtime(true);
        $callback();

        return (hrtime(true) - $start) / 1_000_000;
    }

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
