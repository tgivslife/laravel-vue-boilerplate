<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use SessionHandlerInterface;
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

    /**
     * The password proves identity, not consequence: the last active holder of a lockout permission would leave
     * nobody able to administer it, so the self-service door refuses like the admin door does.
     */
    public function test_the_last_active_holder_of_a_lockout_permission_cannot_delete_their_account(): void
    {
        $user = $this->holderOf('users.manage');
        $user->createToken('cli-token');
        $otherSessionId = $this->createOtherSessionFor($user);

        $this->actingAsStateful($user)
            ->deleteJson('/api/account', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.detail', __('api.access.last_manager_self'));

        $this->assertNull($user->refresh()->deleted_at);
        $this->assertSame($user->email, User::find($user->getKey())->email);
        $this->assertDatabaseMissing('access_audit_logs', ['action' => 'user.self_deleted']);

        // A refused retirement leaves every credential in place, sessions included: those live outside the
        // transaction, so the answer has to come before anything touches them.
        $this->assertSame(1, $user->tokens()->count());
        $this->assertTrue($this->sessionExists($otherSessionId));
        $this->assertDatabaseHas('user_sessions', ['session_id' => $otherSessionId]);

        // The session was not ended: the refusal is a validation answer, not a sign-out.
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->getKey());
    }

    /**
     * The session sweep runs after commit, so a failure there cannot undo the retirement: it is reported, and the
     * request still answers as the success it is. The retired account's sessions authenticate nobody either way.
     */
    public function test_a_failed_session_sweep_after_the_retirement_is_reported_not_returned(): void
    {
        Exceptions::fake();
        $user = $this->createUser();
        $otherSessionId = $this->createOtherSessionFor($user);

        // The store fails on exactly the sweep's target, so the request's own session keeps working.
        $this->app['session']->extend('failing', static fn() => new class(
            new ArraySessionHandler(120), $otherSessionId
        ) implements SessionHandlerInterface {
            public function __construct(
                private readonly SessionHandlerInterface $inner,
                private readonly string $failingId
            ) {
            }

            public function open(string $path, string $name): bool
            {
                return $this->inner->open($path, $name);
            }

            public function close(): bool
            {
                return $this->inner->close();
            }

            public function read(string $id): string|false
            {
                return $this->inner->read($id);
            }

            public function write(string $id, string $data): bool
            {
                return $this->inner->write($id, $data);
            }

            public function destroy(string $id): bool
            {
                if ($id === $this->failingId) {
                    throw new RuntimeException('session store unavailable');
                }

                return $this->inner->destroy($id);
            }

            public function gc(int $max_lifetime): int|false
            {
                return $this->inner->gc($max_lifetime);
            }
        });
        config(['session.driver' => 'failing']);

        $this->actingAsStateful($user)
            ->deleteJson('/api/account', ['password' => 'password'])
            ->assertStatus(200);

        $this->assertSoftDeleted($user);
        Exceptions::assertReported(
            static fn(RuntimeException $exception): bool => $exception->getMessage() === 'session store unavailable'
        );
    }

    public function test_a_holder_with_another_active_holder_can_delete_their_account(): void
    {
        $user = $this->holderOf('users.manage');
        $this->holderOf('users.manage');

        $this->actingAsStateful($user)
            ->deleteJson('/api/account', ['password' => 'password'])
            ->assertStatus(200);

        $this->assertSoftDeleted($user);
    }

    private function holderOf(string $permission): User
    {
        $user = $this->createUser();
        $user->givePermissionTo(
            config('permission.models.permission')::findOrCreate($permission, config('access.guard'))
        );

        return $user;
    }
}
