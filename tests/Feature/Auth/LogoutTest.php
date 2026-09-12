<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_logout_revokes_the_current_access_token(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->postJson('/api/logout')->assertNoContent();

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

    public function test_session_logout_signs_the_session_out(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)->postJson('/api/logout')->assertNoContent();

        $this->assertFalse(Auth::guard('web')->check());
    }
}
