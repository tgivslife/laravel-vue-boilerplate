<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectedIdentitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys by user id, which repeats across tests under RefreshDatabase;
        // Without a flush the counters bleed between tests.
        $this->app['cache']->flush();

        config([
            'security.identity_providers.enabled' => true,
            'security.identity_providers.providers.roeid.enabled' => true,
            'services.roeid.issuer' => 'https://sso.test',
            'services.roeid.client_id' => 'acme-client',
            'services.roeid.client_secret' => 'client-secret',
            'services.roeid.redirect' => '/auth/roeid/callback',
            // Pinned off: the suite asserts 'id' is listed-but-unavailable, and real credentials in the local .env would leak in.
            'security.identity_providers.providers.id.enabled' => false,
        ]);
    }

    public function test_the_list_covers_every_configured_provider(): void
    {
        $user = $this->createUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $response = $this->actingAsStateful($user)->getJson('/api/identities');

        $response->assertStatus(200)->assertJsonCount(2, 'data.identities');

        // The SPA shows the card because the deployment flag says so.
        $this->getJson('/api/user')->assertJsonPath('data.identity_providers_available', true);

        $identities = collect($response->json('data.identities'))->keyBy('provider');

        $this->assertTrue($identities['roeid']['linked']);
        $this->assertTrue($identities['roeid']['available']);
        $this->assertNotNull($identities['roeid']['linked_at']);

        // Configured but not enabled: listed so the UI can show it greyed out.
        $this->assertFalse($identities['id']['linked']);
        $this->assertFalse($identities['id']['available']);
    }

    public function test_the_kill_switch_removes_the_endpoints(): void
    {
        config(['security.identity_providers.enabled' => false]);

        $user = $this->createUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);
        $this->actingAsStateful($user);

        // The SPA reads this flag to hide the identities surfaces.
        $this->getJson('/api/user')->assertJsonPath('data.identity_providers_available', false);

        $this->getJson('/api/identities')->assertStatus(404);
        $this->deleteJson('/api/identities/roeid', ['password' => 'password'])->assertStatus(404);

        // The link itself survives, inert, for when the feature returns.
        $this->assertSame(1, $user->identities()->count());
    }

    public function test_an_identity_can_be_disconnected_with_the_password(): void
    {
        $user = $this->createUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $this->actingAsStateful($user)
            ->deleteJson('/api/identities/roeid', ['password' => 'password'])
            ->assertStatus(204);

        $this->assertSame(0, $user->identities()->count());

        // Self-service security event: audited with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.identity_unlinked',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_disconnecting_requires_the_password(): void
    {
        $user = $this->createUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $this->actingAsStateful($user)
            ->deleteJson('/api/identities/roeid', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertSame(1, $user->identities()->count());
    }

    public function test_passwordless_users_disconnect_without_a_password(): void
    {
        // refresh() loads DB-defaulted columns (is_active) that the factory instance lacks;
        // the Referer marks the request as first-party so Sanctum treats the session user as a TransientToken.
        $user = $this->createUser(['password' => null])->refresh();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $this->actingAs($user)->withHeader('Referer', config('app.url'));

        $this->deleteJson('/api/identities/roeid')->assertStatus(204);

        $this->assertSame(0, $user->identities()->count());
    }

    public function test_disconnecting_an_unlinked_provider_is_not_found(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->deleteJson('/api/identities/roeid', ['password' => 'password'])
            ->assertStatus(404);
    }

    public function test_api_tokens_cannot_manage_identities(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->getJson('/api/identities')->assertStatus(403);
        $this->actingAsStateless($user)
            ->deleteJson('/api/identities/roeid', ['password' => 'password'])
            ->assertStatus(403);
    }
}
