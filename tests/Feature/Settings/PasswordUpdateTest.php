<?php

namespace Tests\Feature\Settings;

use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys by user id, which repeats across tests under RefreshDatabase;
        // without a flush the counters bleed between tests.
        $this->app['cache']->flush();
    }

    public function test_user_can_change_their_password(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('new-sturdy-passphrase', $user->getAttribute('password')));
        $this->assertNotNull($user->password_changed_at);
        $this->assertNotNull($user->remember_token);

        // Credential-lifecycle event: audited with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.password_changed',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $user->refresh()->getAttribute('password')));
    }

    public function test_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'different',
        ])->assertStatus(422);

        $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_passwordless_user_sets_a_first_password_without_a_current_one(): void
    {
        // refresh() loads DB-defaulted columns (is_active) that the factory instance lacks;
        // the Referer marks the request as first-party so Sanctum treats the session user as a TransientToken.
        $user = $this->createUser(['password' => null])->refresh();

        $this->actingAs($user)->withHeader('Referer', config('app.url'));

        $this->putJson('/api/password', [
            'password' => 'first-sturdy-passphrase',
            'password_confirmation' => 'first-sturdy-passphrase',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('first-sturdy-passphrase', $user->refresh()->getAttribute('password')));
    }

    public function test_password_change_signs_out_other_sessions_by_default(): void
    {
        $user = $this->createUser();
        $otherSessionId = $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);

        $this->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(200);

        $this->assertDatabaseMissing('user_sessions', ['session_id' => $otherSessionId]);
        $this->assertFalse($this->sessionExists($otherSessionId));
        // The current session survives the purge.
        $this->assertSame(1, DB::table('user_sessions')->where('user_id', $user->getKey())->count());
    }

    public function test_password_change_can_keep_other_sessions(): void
    {
        $user = $this->createUser();
        $otherSessionId = $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);

        $this->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
            'revoke_other_sessions' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('user_sessions', ['session_id' => $otherSessionId]);
        $this->assertTrue($this->sessionExists($otherSessionId));
    }

    public function test_password_change_spares_personal_access_tokens(): void
    {
        $user = $this->createUser();
        $user->createToken('integration-token');

        $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(200);

        // Deliberate asymmetry with the reset flow: a routine password rotation must not sever legitimate integrations,
        // only the recovery path revokes tokens.
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_password_change_notifies_the_account_owner(): void
    {
        Notification::fake();
        $user = $this->createUser();

        $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(200);

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_password_change_notification_can_be_disabled(): void
    {
        config(['security.authentication_log.password_changed_notification.enabled' => false]);
        Notification::fake();
        $user = $this->createUser();

        $this->actingAsStateful($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(200);

        Notification::assertNotSentTo($user, PasswordChangedNotification::class);
    }

    public function test_password_change_attempts_are_rate_limited(): void
    {
        config(['security.password_confirm_limit.max_attempts' => 2]);
        $user = $this->createUser();

        $this->actingAsStateful($user);

        $payload = [
            'current_password' => 'wrong-password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ];

        $this->putJson('/api/password', $payload)->assertStatus(422);
        $this->putJson('/api/password', $payload)->assertStatus(422);

        $this->putJson('/api/password', $payload)->assertStatus(429);
    }

    public function test_api_tokens_cannot_change_the_password(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->putJson('/api/password', [
            'current_password' => 'password',
            'password' => 'new-sturdy-passphrase',
            'password_confirmation' => 'new-sturdy-passphrase',
        ])->assertStatus(403);
    }

}
