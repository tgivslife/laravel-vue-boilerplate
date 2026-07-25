<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys by user id, which repeats across tests under RefreshDatabase;
        // Without a flush the counters bleed between tests.
        $this->app['cache']->flush();
    }

    public function test_user_can_delete_their_account_with_their_password(): void
    {
        $user = $this->createUser();
        $originalEmail = $user->email;
        $user->createToken('cli-token');
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-1']);
        $otherSessionId = $this->createOtherSessionFor($user);

        $response = $this->actingAsStateful($user)
            ->deleteJson('/api/account', ['password' => 'password']);

        $response->assertStatus(200);
        $this->assertSoftDeleted($user);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->getKey()]);
        $this->assertFalse($this->sessionExists($otherSessionId));

        // Identity links die with the account: a dead account must not squat
        // its provider subject against a future re-registration.
        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->getKey()]);

        // Same retirement mechanics as the admin delete: the address is
        // tombstoned out of the unique index, membership stays answerable.
        $deleted = User::withTrashed()->find($user->getKey());
        $this->assertStringEndsWith('@deleted.invalid', $deleted->email);
        $this->assertSame($deleted->id, User::onlyTrashed()->whereDeletedEmail($originalEmail)->sole()->id);

        // Removing every way into an account belongs in the trail, whoever did it.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.self_deleted',
            'actor_id' => $user->getKey(),
            'subject_id' => $user->getKey(),
        ]);
    }

    public function test_wrong_password_does_not_delete_the_account(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->deleteJson('/api/account', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertNull($user->refresh()->deleted_at);
    }

    public function test_passwordless_user_confirms_with_their_email(): void
    {
        // refresh() loads DB-defaulted columns (is_active) that the factory
        // instance lacks; the Referer marks the request as first-party so
        // Sanctum treats the session user as a TransientToken.
        $user = $this->createUser(['password' => null])->refresh();

        $this->actingAs($user)->withHeader('Referer', config('app.url'));

        $this->deleteJson('/api/account', ['email' => 'wrong@example.com'])->assertStatus(422);
        $this->deleteJson('/api/account', ['email' => $user->email])->assertStatus(200);

        $this->assertSoftDeleted($user);
    }

    public function test_soft_deleted_user_cannot_sign_in_again(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->deleteJson('/api/account', ['password' => 'password'])
            ->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(401);
    }

    public function test_delete_attempts_are_rate_limited(): void
    {
        config(['security.password_confirm_limit.max_attempts' => 2]);
        $user = $this->createUser();

        $this->actingAsStateful($user);

        $this->deleteJson('/api/account', ['password' => 'wrong-password'])->assertStatus(422);
        $this->deleteJson('/api/account', ['password' => 'wrong-password'])->assertStatus(422);

        $this->deleteJson('/api/account', ['password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_api_tokens_cannot_delete_the_account(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)
            ->deleteJson('/api/account', ['password' => 'password'])
            ->assertStatus(403);
    }

}
