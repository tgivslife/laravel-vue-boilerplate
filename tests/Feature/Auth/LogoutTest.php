<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateless($user)->postJson('/api/logout');

        $response->assertNoContent();
    }

    public function test_logout_revokes_the_current_access_token(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->postJson('/api/logout');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertUnauthorized();
    }

    public function test_logout_revokes_the_token_even_when_the_request_looks_first_party(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_web_authenticated_user_can_logout(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateful($user)->postJson('/api/logout');

        $response->assertNoContent();
    }

    public function test_web_logout_invalidates_the_session(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)->postJson('/api/logout');

        $this->assertFalse(Auth::guard('web')->check());
    }
}
