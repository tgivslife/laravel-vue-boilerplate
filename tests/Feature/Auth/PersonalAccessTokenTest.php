<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PersonalAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_user_can_create_a_token(): void
    {
        $user = $this->createUser();

        $response = $this->createTokenAs($user, ['name' => 'ci-integration']);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'ci-integration');
        $response->assertJsonPath('data.abilities', ['*']);
        $response->assertJsonStructure(['meta' => ['token']]);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_created_token_authenticates_api_requests(): void
    {
        $user = $this->createUser();

        $plainText = $this->createTokenAs($user, ['name' => 'ci-integration'])->json('meta.token');

        // Drop the first-party Referer and memoized guards so the next
        // request authenticates through the bearer token alone.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($plainText)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
    }

    public function test_token_creation_requires_the_current_password(): void
    {
        $user = $this->createUser();

        $response = $this->createTokenAs($user, [
            'name' => 'ci-integration',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'password');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_passwordless_user_cannot_create_a_token(): void
    {
        // Magic-link-only accounts have no password to confirm, so token
        // creation is unavailable to them until they set one.
        // refresh() loads DB-defaulted columns (is_active) that the factory
        // instance lacks - actingAs() hands this exact instance to the
        // account-state middleware.
        $user = $this->createUser(['password' => null])->refresh();

        $response = $this->actingAs($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/tokens', [
                'name' => 'ci-integration',
                'password' => 'password',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'password');
    }

    public function test_token_creation_requires_a_session(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateless($user)->postJson('/api/tokens', [
            'name' => 'ci-integration',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('detail', __('api.auth.tokens.session_required'));

        // Only the bearer token that authenticated the request exists.
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_token_listing_requires_a_session(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateless($user)->getJson('/api/tokens');

        $response->assertStatus(403);
        $response->assertJsonPath('detail', __('api.auth.tokens.session_required'));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/tokens')->assertUnauthorized();
        $this->postJson('/api/tokens', ['name' => 'x', 'password' => 'password'])->assertUnauthorized();
    }

    public function test_index_lists_only_own_tokens_and_never_their_values(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $user->createToken('mine', ['*'], now()->addDay());
        $other->createToken('not-mine', ['*'], now()->addDay());

        $response = $this->actingAsStateful($user)->getJson('/api/tokens');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'mine');
        $response->assertJsonMissingPath('data.0.token');
    }

    public function test_default_lifetime_is_applied(): void
    {
        $this->freezeTime();
        config(['security.personal_access_tokens.default_lifetime_days' => 30]);
        $user = $this->createUser();

        $this->createTokenAs($user, ['name' => 'ci-integration'])->assertCreated();

        // The column stores second precision; drop the frozen clock's microseconds.
        $this->assertTrue($user->tokens()->first()->expires_at->equalTo(now()->addDays(30)->startOfSecond()));
    }

    public function test_requested_lifetime_is_capped_by_config(): void
    {
        config(['security.personal_access_tokens.max_lifetime_days' => 365]);
        $user = $this->createUser();

        $response = $this->createTokenAs($user, [
            'name' => 'ci-integration',
            'expires_in_days' => 9999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'expires_in_days');
    }

    public function test_abilities_must_be_permissions_the_user_holds(): void
    {
        $user = $this->createUser();
        Permission::create(['name' => 'users.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The permission exists but was never granted to this user.
        $response = $this->createTokenAs($user, [
            'name' => 'ci-integration',
            'abilities' => ['users.view'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.name', 'abilities.0');
    }

    public function test_token_can_be_scoped_to_a_held_permission(): void
    {
        $user = $this->createUser();
        Permission::create(['name' => 'users.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->givePermissionTo('users.view');

        $response = $this->createTokenAs($user, [
            'name' => 'ci-integration',
            'abilities' => ['users.view'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.abilities', ['users.view']);
        $this->assertSame(['users.view'], $user->tokens()->first()->abilities);
    }

    public function test_user_can_revoke_their_own_token(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('mine', ['*'], now()->addDay());

        $response = $this->actingAsStateful($user)
            ->deleteJson('/api/tokens/'.$token->accessToken->getKey());

        $response->assertNoContent();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_user_cannot_revoke_another_users_token(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $token = $other->createToken('not-mine', ['*'], now()->addDay());

        $response = $this->actingAsStateful($user)
            ->deleteJson('/api/tokens/'.$token->accessToken->getKey());

        $response->assertNotFound();
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_token_creation_is_rate_limited(): void
    {
        config(['security.personal_access_tokens.create_limit.max_attempts' => 2]);
        $user = $this->createUser();

        $this->createTokenAs($user, ['name' => 'first'])->assertCreated();
        $this->createTokenAs($user, ['name' => 'second'])->assertCreated();

        $response = $this->createTokenAs($user, ['name' => 'third']);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }

    /**
     * Create a token through the endpoint as a session-authenticated user.
     *
     * @param  array<string, mixed>  $payload  Merged over a valid default body.
     */
    private function createTokenAs(User $user, array $payload): TestResponse
    {
        if (!$this->app['auth']->guard('web')->check()) {
            $this->actingAsStateful($user);
        }

        return $this->postJson('/api/tokens', array_merge(['password' => 'password'], $payload));
    }
}
