<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\TwoFactorDisabledNotification;
use App\Notifications\TwoFactorEnabledNotification;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $twoFactor;

    private Google2FA $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys per user+IP, identical for every test in this file; without a flush the counters bleed across tests.
        $this->app['cache']->flush();

        $this->twoFactor = $this->app->make(TwoFactorService::class);
        $this->engine = $this->app->make(Google2FA::class);
    }

    public function test_enrollment_requires_the_current_password(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $this->postJson('/api/two-factor')->assertStatus(422);
        $this->postJson('/api/two-factor', ['password' => 'wrong'])->assertStatus(422);

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_enrollment_returns_the_authenticator_setup(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $response = $this->postJson('/api/two-factor', ['password' => 'password'])->assertOk();

        $secret = $response->json('data.secret');
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertStringContainsString('secret='.$secret, $response->json('data.otpauth_url'));
        $this->assertStringContainsString('<svg', $response->json('data.qr_svg'));

        // Minted but unconfirmed: the flag stays off until a code proves it.
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->getJson('/api/user')
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.two_factor_available', true);
    }

    public function test_a_passwordless_account_enrolls_without_a_password(): void
    {
        // refresh() loads DB-defaulted columns (is_active) that the factory instance lacks;
        // the Referer marks the request as first-party so Sanctum treats the session user as a TransientToken.
        $user = $this->createUser(['password' => null])->refresh();

        $this->actingAs($user)->withHeader('Referer', config('app.url'));

        $this->postJson('/api/two-factor')->assertOk();
    }

    public function test_enrollment_refuses_while_the_factor_is_active(): void
    {
        $user = $this->enrolledUser();
        $this->actingAsEnrolled($user);

        $this->postJson('/api/two-factor', ['password' => 'password'])->assertStatus(409);
    }

    public function test_confirmation_activates_and_returns_recovery_codes_once(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $secret = $this->postJson('/api/two-factor', ['password' => 'password'])->json('data.secret');

        $response = $this->postJson('/api/two-factor/confirm', [
            'code' => $this->engine->getCurrentOtp($secret),
        ])->assertOk();

        $this->assertCount(8, $response->json('data.recovery_codes'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->getJson('/api/user')->assertJsonPath('data.two_factor_enabled', true);

        // Self-service security event: audited with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.two_factor_enabled',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_confirmation_refuses_a_wrong_code_and_a_missing_setup(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        // No enrollment in progress.
        $this->postJson('/api/two-factor/confirm', ['code' => '123456'])->assertStatus(422);

        $secret = $this->postJson('/api/two-factor', ['password' => 'password'])->json('data.secret');

        $wrong = $this->engine->getCurrentOtp($secret) === '000000' ? '111111' : '000000';
        $this->postJson('/api/two-factor/confirm', ['code' => $wrong])->assertStatus(422);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disabling_requires_the_password_and_clears_the_factor(): void
    {
        $user = $this->enrolledUser();
        $this->actingAsEnrolled($user);

        $this->deleteJson('/api/two-factor', ['password' => 'wrong'])->assertStatus(422);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->deleteJson('/api/two-factor', ['password' => 'password'])->assertOk();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->hasTwoFactorEnabled());

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.two_factor_disabled',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_regenerating_recovery_codes_replaces_the_set(): void
    {
        $user = $this->enrolledUser();
        $this->actingAsEnrolled($user);

        $response = $this->postJson('/api/two-factor/recovery-codes', ['password' => 'password'])->assertOk();

        $codes = $response->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        // The returned plaintext matches the stored hashes.
        $this->assertTrue($this->twoFactor->redeemRecoveryCode($user->fresh(), $codes[0]));
    }

    public function test_regenerating_refuses_without_an_active_factor(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $this->postJson('/api/two-factor/recovery-codes', ['password' => 'password'])->assertStatus(409);
    }

    public function test_the_owner_is_mailed_when_the_factor_changes(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $this->actingAsStateful($user);

        $secret = $this->postJson('/api/two-factor', ['password' => 'password'])->json('data.secret');
        $this->postJson('/api/two-factor/confirm', ['code' => $this->engine->getCurrentOtp($secret)])->assertOk();

        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);

        $this->deleteJson('/api/two-factor', ['password' => 'password'])->assertOk();

        Notification::assertSentTo($user, TwoFactorDisabledNotification::class);
    }

    public function test_discarding_a_pending_setup_sends_no_mail(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $this->actingAsStateful($user);

        $this->postJson('/api/two-factor', ['password' => 'password'])->assertOk();
        $this->deleteJson('/api/two-factor', ['password' => 'password'])->assertOk();

        Notification::assertNotSentTo($user, TwoFactorDisabledNotification::class);
        // Discarding a pending wizard is not a security event either way.
        $this->assertDatabaseMissing('access_audit_logs', ['action' => 'user.two_factor_disabled']);
    }

    public function test_the_kill_switch_removes_the_endpoints(): void
    {
        config(['security.two_factor.enabled' => false]);

        $user = $this->createUser();
        $this->actingAsStateful($user);

        // The SPA reads this flag to hide every two-factor surface.
        $this->getJson('/api/user')->assertJsonPath('data.two_factor_available', false);

        $this->postJson('/api/two-factor', ['password' => 'password'])->assertStatus(404);
        $this->postJson('/api/two-factor/confirm', ['code' => '123456'])->assertStatus(404);
        $this->deleteJson('/api/two-factor', ['password' => 'password'])->assertStatus(404);
        $this->postJson('/api/two-factor/recovery-codes', ['password' => 'password'])->assertStatus(404);
    }

    public function test_two_factor_management_is_session_only(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)
            ->postJson('/api/two-factor', ['password' => 'password'])
            ->assertStatus(403);
    }

    public function test_guests_cannot_manage_two_factor(): void
    {
        $this->postJson('/api/two-factor', ['password' => 'password'])->assertStatus(401);
    }

    /**
     * Authenticate an enrolled user at the framework level: the login  endpoint would park them behind
     * the two-factor challenge instead of opening a session, and the challenge flow is not this suite's concern.
     * refresh() loads DB-defaulted columns (is_active) that the factory instance lacks;
     * the Referer marks requests as first-party for Sanctum.
     */
    private function actingAsEnrolled(User $user): void
    {
        $this->actingAs($user->refresh())->withHeader('Referer', config('app.url'));
    }

    /**
     * A user with a confirmed enrollment (activated through the service; the HTTP wizard is exercised by the tests above).
     */
    private function enrolledUser(): User
    {
        $user = $this->createUser();

        $enrollment = $this->twoFactor->startEnrollment($user);
        $this->twoFactor->confirmEnrollment($user, $this->engine->getCurrentOtp($enrollment->secret));

        return $user;
    }
}
