<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a stateful (session-based) client by hitting the login
     * endpoint with a Referer header. The Referer persists for all subsequent
     * requests in the test, so they are recognized as first-party by Sanctum.
     */
    protected function actingAsStateful(User $user, string $password = 'password'): static
    {
        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => $password,
            ]);

        return $this;
    }

    /**
     * Authenticate as a stateless (token-based) API client by issuing a
     * Sanctum personal access token and wiring it to the Authorization header.
     */
    protected function actingAsStateless(User $user): static
    {
        $this->withToken($user->createToken('test-token')->plainTextToken);

        return $this;
    }

    /**
     * Create a verified user via the factory. remember_token starts as null
     * so auth tests that assert its state begin from a clean slate.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['remember_token' => null], $attributes));
    }

    /**
     * Log in through the HTTP endpoint and carry the session cookie into
     * every subsequent request. The test client does not persist cookies
     * on its own, and without the cookie each request would start a fresh
     * session - there would be no stable "current session" to assert on.
     * Callers must switch to the database session driver first when they
     * assert on session rows.
     */
    protected function loginAndCarrySession(User $user, string $password = 'password'): void
    {
        $response = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => $password]);

        $response->assertStatus(200);

        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn(object $cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($sessionCookie);

        // The value is already encrypted by the response; send it as-is so
        // the EncryptCookies middleware decrypts it like a real browser's.
        // withCredentials() is required because json requests drop cookies
        // by default (prepareCookiesForJsonRequest).
        $this->withCredentials();
        $this->withUnencryptedCookie((string) config('session.cookie'), (string) $sessionCookie->getValue());
    }

    /**
     * Create a live session for the user - written through the configured
     * session driver's handler and indexed in the session registry - as
     * another signed-in browser's session would exist. Returns the raw id.
     */
    protected function createOtherSessionFor(User $user): string
    {
        $sessionId = Str::random(40);

        app('session')->driver()->getHandler()->write($sessionId, serialize(['_token' => Str::random(40)]));

        DB::table('user_sessions')->insert([
            'user_id' => $user->getKey(),
            'session_id' => $sessionId,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'last_activity' => now()->subMinutes(5)->getTimestamp(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $sessionId;
    }

    /**
     * Whether a session still exists in the configured session driver.
     */
    protected function sessionExists(string $sessionId): bool
    {
        return app('session')->driver()->getHandler()->read($sessionId) !== '';
    }
}
