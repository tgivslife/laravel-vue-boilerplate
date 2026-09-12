<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The magic-link and password-reset decisions sleep to a floor in production; the suite runs them at zero.
        // The tests that pin the floor pass their own.
        config(['security.auth_decision_floor_ms' => 0]);
    }

    /**
     * Authenticate as a stateful (session-based) client by hitting the login endpoint with a Referer header.
     * The Referer persists for all subsequent requests in the test, so they are recognized as first-party by Sanctum.
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
     * Authenticate as a stateless (token-based) API client by issuing a Sanctum personal access token and wiring it to the Authorization header.
     */
    protected function actingAsStateless(User $user): static
    {
        $this->withToken($user->createToken('test-token')->plainTextToken);

        return $this;
    }

    /**
     * Create a verified user via the factory. remember_token starts as null so auth tests that assert its state begin from a clean slate.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['remember_token' => null], $attributes));
    }

    /**
     * Log in through the HTTP endpoint and carry the session cookie into every subsequent request.
     * The test client does not persist cookies on its own; without one, each request starts on a fresh session id and
     * there is no stable "current session" to assert on.
     */
    protected function loginAndCarrySession(User $user, string $password = 'password'): void
    {
        $response = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => $password]);

        $response->assertStatus(200);

        $this->carrySessionCookieFrom($response);
    }

    /**
     * Carry the session cookie a response set into every subsequent request - a sign-in, or a request that
     * regenerated the session id and handed the browser its replacement cookie.
     */
    protected function carrySessionCookieFrom(TestResponse $response): void
    {
        $this->carryCookieFrom($response, (string) config('session.cookie'));
    }

    /**
     * Carry a cookie a response set into every subsequent request, as a browser would.
     */
    protected function carryCookieFrom(TestResponse $response, string $name): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(static fn(object $cookie): bool => $cookie->getName() === $name);

        $this->assertNotNull($cookie, "The response set no '{$name}' cookie.");

        // Sent as-is, already encrypted, for EncryptCookies to decrypt like a browser's; json requests drop cookies without withCredentials().
        $this->withCredentials();
        $this->withUnencryptedCookie($name, (string) $cookie->getValue());
    }

    /**
     * Make the session registry refuse writes of one kind (INSERT, UPDATE or DELETE) for the rest of the test, or
     * until allowRegistryWrites(): how a registry that is down at the wrong moment is simulated.
     * SQLite only, which is what the suite runs on; the trigger is rolled back with the test's transaction.
     */
    protected function failRegistryWrites(string $statement): void
    {
        DB::unprepared(
            "CREATE TRIGGER fail_registry_{$statement} BEFORE {$statement} ON user_sessions "
            ."BEGIN SELECT RAISE(ABORT, 'session registry unavailable'); END"
        );
    }

    protected function allowRegistryWrites(string $statement): void
    {
        DB::unprepared("DROP TRIGGER fail_registry_{$statement}");
    }

    /**
     * Stop carrying the session cookie: the next request arrives with no session, as from another browser.
     */
    protected function dropSessionCookie(): void
    {
        $this->dropCookie((string) config('session.cookie'));
    }

    /**
     * Stop carrying a cookie, as a browser that discarded it would.
     */
    protected function dropCookie(string $name): void
    {
        unset($this->unencryptedCookies[$name]);
    }

    /**
     * The session as the store persisted it, reloaded from the driver.
     * The test client keeps a copy of the session in memory across requests, which is emptied first so that only what was saved comes back.
     */
    protected function persistedSession(string $sessionId): Store
    {
        $store = $this->app['session']->driver();
        $store->flush();
        $store->setId($sessionId);
        $store->start();

        return $store;
    }

    /**
     * Create a live session for the user, as another signed-in browser's would exist: written to the session driver,
     * registered, and naming its row from its payload. Returns the raw session id.
     */
    protected function createOtherSessionFor(User $user, bool $remembered = false): string
    {
        $sessionId = Str::random(40);

        $rowId = DB::table('user_sessions')->insertGetId([
            'user_id' => $user->getKey(),
            'session_id' => $sessionId,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'remembered' => $remembered,
            'last_activity' => now()->subMinutes(5)->getTimestamp(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app('session')->driver()->getHandler()->write($sessionId, serialize([
            '_token' => Str::random(40),
            SessionRegistry::SESSION_KEY => $rowId,
        ]));

        return $sessionId;
    }

    /**
     * Whether a session still exists in the configured session driver.
     */
    protected function sessionExists(string $sessionId): bool
    {
        return app('session')->driver()->getHandler()->read($sessionId) !== '';
    }

    /**
     * Asserts the session was revoked: gone from the driver, its registry row kept as a tombstone.
     */
    protected function assertSessionRevoked(string $sessionId): void
    {
        $this->assertFalse($this->sessionExists($sessionId), 'The session still exists in the driver.');
        $this->assertTrue(
            DB::table('user_sessions')->where('session_id', $sessionId)->whereNotNull('revoked_at')->exists(),
            'The session has no revocation tombstone.'
        );
    }

    /**
     * The registry row id currently holding the given session id.
     */
    protected function registryRowId(string $sessionId): int
    {
        return (int) DB::table('user_sessions')->where('session_id', $sessionId)->value('id');
    }

    /**
     * The user's registry rows that are not tombstones.
     */
    protected function liveSessionCount(User $user): int
    {
        return DB::table('user_sessions')->where('user_id', $user->getKey())->whereNull('revoked_at')->count();
    }

    /**
     * Asserts the database refuses a write, without poisoning the transaction the test runs in.
     *
     * Postgres aborts the whole transaction on a failed statement (every later query answers 25P02), and
     * RefreshDatabase wraps each test in one, so a bare try/catch around a deliberate violation takes the rest of
     * the test down. A nested transaction gives the failure a savepoint to roll back to instead.
     *
     * @param  callable():mixed  $write  The write the schema is expected to reject.
     * @param  string  $because  What the failure would mean, reported when the write is accepted.
     */
    protected function assertDatabaseRejects(callable $write, string $because): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $exception) {
            // 23xxx is the integrity-constraint class; a typo'd column throws QueryException too, and would prove nothing.
            $this->assertSame('23', substr((string) $exception->getCode(), 0, 2),
                "Expected an integrity-constraint violation, got SQLSTATE {$exception->getCode()}: "
                .$exception->getMessage());

            return;
        }

        $this->fail($because);
    }
}
