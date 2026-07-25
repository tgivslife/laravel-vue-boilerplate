<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys by user id, which repeats
        // across tests under RefreshDatabase; without a flush the counters
        // bleed between tests.
        $this->app['cache']->flush();
    }

    public function test_sessions_list_flags_the_current_session(): void
    {
        $user = $this->createUser();

        $this->loginAndCarrySession($user);
        $response = $this->getJson('/api/sessions');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.is_current', true)
            ->assertJsonPath('data.total', 1);
    }

    public function test_the_list_is_soft_capped_but_reports_the_total(): void
    {
        config(['security.session_registry.display_limit' => 2]);
        $user = $this->createUser();
        $this->createOtherSessionFor($user);
        $this->createOtherSessionFor($user);
        $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);

        $this->getJson('/api/sessions')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.total', 4);
    }

    public function test_other_sessions_are_listed_with_device_details(): void
    {
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);
        $response = $this->getJson('/api/sessions');

        $response->assertStatus(200)->assertJsonCount(2, 'data.sessions');

        $other = collect($response->json('data.sessions'))->firstWhere('is_current', false);
        $this->assertSame('198.51.100.7', $other['ip_address']);
        $this->assertStringContainsString('Windows', $other['device_name']);
        // Sessions are addressed by digest; a raw session id is 40 chars.
        $this->assertSame(64, strlen($other['id']));
    }

    public function test_a_single_session_can_be_revoked(): void
    {
        $user = $this->createUser();
        $rawId = $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);
        $other = collect($this->getJson('/api/sessions')->json('data.sessions'))->firstWhere('is_current', false);

        $this->deleteJson('/api/sessions/'.$other['id'])->assertStatus(204);

        $this->assertDatabaseMissing('user_sessions', ['session_id' => $rawId]);
        // The underlying session is destroyed in the driver, not just unlisted.
        $this->assertFalse($this->sessionExists($rawId));
    }

    public function test_the_current_session_cannot_be_revoked(): void
    {
        $user = $this->createUser();

        $this->loginAndCarrySession($user);
        $current = collect($this->getJson('/api/sessions')->json('data.sessions'))->firstWhere('is_current', true);

        $this->deleteJson('/api/sessions/'.$current['id'])->assertStatus(422);
    }

    public function test_revoking_an_unknown_session_returns_not_found(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->deleteJson('/api/sessions/'.str_repeat('a', 64))
            ->assertStatus(404);
    }

    public function test_destroy_others_requires_the_password(): void
    {
        $user = $this->createUser();
        $rawId = $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);

        $this->deleteJson('/api/sessions/others', [])->assertStatus(422);
        $this->assertDatabaseHas('user_sessions', ['session_id' => $rawId]);

        $this->deleteJson('/api/sessions/others', ['password' => 'password'])->assertStatus(200);

        $this->assertDatabaseMissing('user_sessions', ['session_id' => $rawId]);
        $this->assertFalse($this->sessionExists($rawId));
        $this->assertSame(1, DB::table('user_sessions')->where('user_id', $user->getKey())->count());
        $this->assertNotNull($user->refresh()->remember_token);
    }

    public function test_passwordless_user_can_destroy_others_without_a_password(): void
    {
        // refresh() loads DB-defaulted columns (is_active) that the factory
        // instance lacks; the Referer marks the request as first-party so
        // Sanctum treats the session user as a TransientToken.
        $user = $this->createUser(['password' => null])->refresh();
        $rawId = $this->createOtherSessionFor($user);

        $this->actingAs($user)->withHeader('Referer', config('app.url'));

        $this->deleteJson('/api/sessions/others')->assertStatus(200);

        $this->assertDatabaseMissing('user_sessions', ['session_id' => $rawId]);
    }

    public function test_api_tokens_cannot_list_sessions(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->getJson('/api/sessions')->assertStatus(403);
    }

}
