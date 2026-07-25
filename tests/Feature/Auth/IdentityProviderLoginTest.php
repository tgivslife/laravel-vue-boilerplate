<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IdentityProviderLoginTest extends TestCase
{
    use RefreshDatabase;

    /** The id_token the faked token endpoint will return next. */
    private ?string $pendingIdToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Discovery/JWKS caches and the OIDC limiter (keyed by the shared test IP) both live in the cache and bleed across tests.
        $this->app['cache']->flush();

        config([
            'security.identity_providers.enabled' => true,
            'security.identity_providers.providers.roeid.enabled' => true,
            'security.identity_providers.providers.roeid.link_policy' => 'explicit',
            'services.roeid.issuer' => 'https://sso.test',
            'services.roeid.client_id' => 'acme-client',
            'services.roeid.client_secret' => 'client-secret',
            'services.roeid.redirect' => '/auth/roeid/callback',
            // Pinned off: the suite asserts on the provider list, and real
            // 'id' credentials in the local .env would leak in.
            'security.identity_providers.providers.id.enabled' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://sso.test/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://sso.test',
                'authorization_endpoint' => 'https://sso.test/authorize',
                'token_endpoint' => 'https://sso.test/token',
                'jwks_uri' => 'https://sso.test/jwks',
            ]),
            'https://sso.test/jwks' => Http::response(self::keyMaterial()['jwks']),
            'https://sso.test/token' => fn() => Http::response([
                'access_token' => 'access-token',
                'token_type' => 'Bearer',
                'id_token' => $this->pendingIdToken,
            ]),
        ]);
    }

    /* ------------------------------------------------------------------ *
     *  Redirect
     * ------------------------------------------------------------------ */

    public function test_redirect_points_at_the_provider_with_pkce_and_nonce(): void
    {
        $response = $this->get('/auth/roeid/redirect');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://sso.test/authorize', $location);
        $this->assertSame('acme-client', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);
        $this->assertStringContainsString('openid', $query['scope']);
    }

    public function test_the_methods_endpoint_reflects_the_configuration(): void
    {
        config([
            'security.password_login.enabled' => true,
            'security.magic_link.enabled' => true,
            'security.magic_link.provision' => true,
        ]);

        $this->getJson('/api/auth/methods')
            ->assertStatus(200)
            ->assertJsonPath('data.password', true)
            ->assertJsonPath('data.magic_link', true)
            ->assertJsonPath('data.magic_link_provision', true)
            ->assertJsonPath('data.providers', ['roeid']);

        config([
            'security.password_login.enabled' => false,
            'security.magic_link.enabled' => false,
            'security.identity_providers.providers.roeid.enabled' => false,
        ]);

        // Provisioning is still on, but a disabled door cannot advertise it.
        $this->getJson('/api/auth/methods')
            ->assertStatus(200)
            ->assertJsonPath('data.password', false)
            ->assertJsonPath('data.magic_link', false)
            ->assertJsonPath('data.magic_link_provision', false)
            ->assertJsonPath('data.providers', []);
    }

    public function test_disabled_provider_is_not_found(): void
    {
        config(['security.identity_providers.providers.roeid.enabled' => false]);

        $this->get('/auth/roeid/redirect')->assertNotFound();
        $this->get('/auth/roeid/callback')->assertNotFound();
    }

    public function test_incomplete_credentials_disable_the_provider(): void
    {
        config(['services.roeid.client_id' => null]);

        $this->get('/auth/roeid/redirect')->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     *  Callback
     * ------------------------------------------------------------------ */

    public function test_linked_identity_signs_in(): void
    {
        $user = $this->createUser()->refresh();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));

        $identity = $user->identities()->sole();
        $this->assertNotNull($identity->last_used_at);

        $latestLog = $user->authentications()->latest('id')->first();
        $this->assertTrue($latestLog->login_successful);
        $this->assertSame('roeid', $latestLog->login_method);
    }

    public function test_unlinked_identity_is_rejected_under_the_explicit_policy(): void
    {
        $this->createUser(['email' => 'match@example.com']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'match@example.com',
            'email_verified' => true,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_not_linked'));

        $this->assertSame(0, \App\Models\UserIdentity::query()->count());
    }

    public function test_email_policy_auto_links_a_verified_matching_email(): void
    {
        config(['security.identity_providers.providers.roeid.link_policy' => 'email']);
        $user = $this->createUser(['email' => 'match@example.com'])->refresh();

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'match@example.com',
            'email_verified' => true,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));

        $identity = $user->identities()->sole();
        $this->assertSame('roeid', $identity->provider);
        $this->assertSame('subject-123', $identity->subject);

        // Auto-linking is a new way into the account: audited like an
        // explicit connect, with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.identity_linked',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_email_policy_requires_a_verified_email(): void
    {
        config(['security.identity_providers.providers.roeid.link_policy' => 'email']);
        $this->createUser(['email' => 'match@example.com']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'match@example.com',
            'email_verified' => false,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_not_linked'));
    }

    public function test_email_policy_never_creates_accounts(): void
    {
        config(['security.identity_providers.providers.roeid.link_policy' => 'email']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'nobody@example.com',
            'email_verified' => true,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_not_linked'));

        $this->assertSame(0, User::query()->count());
    }

    public function test_provision_policy_creates_a_roleless_account_and_signs_in(): void
    {
        config(['security.identity_providers.providers.roeid.link_policy' => 'provision']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'new.operator@example.com',
            'email_verified' => true,
            'given_name' => 'Ana',
            'family_name' => 'Popescu',
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));

        $user = User::query()->sole();
        $this->assertSame('new.operator@example.com', $user->email);
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Popescu', $user->last_name);
        $this->assertNotNull($user->email_verified_at);
        // Deny-by-default: authenticated but authorized to nothing.
        $this->assertCount(0, $user->roles);
        $this->assertSame('subject-123', $user->identities()->sole()->subject);

        // The JIT link is a way into the account from day one: audited with the freshly provisioned owner as actor,
        // for both the creation itself and the identity binding.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.self_provisioned',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.identity_linked',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_provision_policy_assigns_the_configured_default_roles(): void
    {
        config([
            'security.identity_providers.providers.roeid.link_policy' => 'provision',
            'access.self_provision_roles' => ['member'],
        ]);
        config('permission.models.role')::findOrCreate('member', config('access.guard'));

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'new.operator@example.com',
            'email_verified' => true,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));

        $user = User::query()->sole();
        $this->assertSame(['member'], $user->roles->pluck('name')->all());
    }

    public function test_provision_policy_refuses_an_email_collision(): void
    {
        config(['security.identity_providers.providers.roeid.link_policy' => 'provision']);
        $this->createUser(['email' => 'taken@example.com']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'taken@example.com',
            'email_verified' => true,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_not_linked'));

        // Neither provisioned nor linked: the existing account stays untouched.
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, \App\Models\UserIdentity::query()->count());
    }

    public function test_provision_policy_requires_a_verified_email(): void
    {
        config(['security.identity_providers.providers.roeid.link_policy' => 'provision']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'new.operator@example.com',
            'email_verified' => false,
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_not_linked'));

        $this->assertSame(0, User::query()->count());
    }

    public function test_provision_claim_gate_blocks_tokens_without_the_membership_claim(): void
    {
        config([
            'security.identity_providers.providers.roeid.link_policy' => 'provision',
            'security.identity_providers.providers.roeid.provision_claim' => 'realm_access.roles',
            'security.identity_providers.providers.roeid.provision_value' => 'acme-access',
        ]);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'new.operator@example.com',
            'email_verified' => true,
            'realm_access' => ['roles' => ['other-app']],
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_not_linked'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_provision_claim_gate_admits_tokens_carrying_the_membership_claim(): void
    {
        config([
            'security.identity_providers.providers.roeid.link_policy' => 'provision',
            'security.identity_providers.providers.roeid.provision_claim' => 'realm_access.roles',
            'security.identity_providers.providers.roeid.provision_value' => 'acme-access',
        ]);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken([
            'nonce' => $flow['nonce'],
            'email' => 'new.operator@example.com',
            'email_verified' => true,
            'realm_access' => ['roles' => ['acme-access', 'other-app']],
        ]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));
        $this->assertSame(1, User::query()->count());
    }

    public function test_deactivated_account_cannot_sign_in(): void
    {
        $user = $this->createUser(['is_active' => false]);
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_unavailable'));
    }

    public function test_a_require_provider_parks_enrolled_accounts_for_the_two_factor_challenge(): void
    {
        config(['security.identity_providers.providers.roeid.two_factor' => 'require']);

        $user = $this->enrolledUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/auth/two-factor'));
        $this->assertGuest();

        // The identity itself verified, so its usage timestamp moves even
        // though the session is still owed the second factor.
        $this->assertNotNull($user->identities()->sole()->last_used_at);

        $engine = $this->app->make(\PragmaRX\Google2FA\Google2FA::class);
        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/two-factor/challenge', [
                'code' => $engine->getCurrentOtp($user->fresh()->two_factor_secret),
            ])
            ->assertOk();

        $this->assertAuthenticatedAs($user);

        $latestLog = $user->authentications()->latest('id')->first();
        $this->assertTrue($latestLog->login_successful);
        $this->assertSame('roeid', $latestLog->login_method);
    }

    public function test_the_default_skip_policy_lets_enrolled_accounts_straight_in(): void
    {
        $user = $this->enrolledUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_require_provider_ignores_unenrolled_accounts(): void
    {
        config(['security.identity_providers.providers.roeid.two_factor' => 'require']);

        $user = $this->createUser()->refresh();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_lands_on_the_requested_internal_redirect(): void
    {
        $user = $this->createUser()->refresh();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow('?redirect='.urlencode('/app/devices'));
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app/devices'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_external_redirect_target_is_ignored(): void
    {
        $user = $this->createUser()->refresh();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow('?redirect='.urlencode('https://evil.test/phish'));
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));
    }

    public function test_a_protocol_relative_redirect_target_is_ignored(): void
    {
        $user = $this->createUser()->refresh();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow('?redirect='.urlencode('//evil.test/phish'));
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app'));
    }

    public function test_the_two_factor_park_carries_the_redirect(): void
    {
        config(['security.identity_providers.providers.roeid.two_factor' => 'require']);

        $user = $this->enrolledUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow('?redirect='.urlencode('/app/devices'));
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)
            ->assertRedirect(url('/auth/two-factor?redirect='.rawurlencode('/app/devices')));
        $this->assertGuest();
    }

    public function test_a_tampered_state_fails_generically(): void
    {
        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->get('/auth/roeid/callback?code=fake-code&state=tampered')
            ->assertRedirect(url('/auth/login?error=identity_failed'));
    }

    public function test_a_wrong_nonce_fails_generically(): void
    {
        $user = $this->createUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);

        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => 'replayed-nonce']);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_failed'));
    }

    public function test_a_wrong_audience_fails_generically(): void
    {
        $flow = $this->startFlow();
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce'], 'aud' => 'someone-else']);

        $this->completeFlow($flow)->assertRedirect(url('/auth/login?error=identity_failed'));
    }

    /* ------------------------------------------------------------------ *
     *  Connect intent (linking from settings)
     * ------------------------------------------------------------------ */

    public function test_connect_intent_links_the_identity_to_the_signed_in_account(): void
    {
        $user = $this->createUser();

        $this->loginAndCarrySession($user);
        $flow = $this->startFlow('?intent=connect');
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)->assertRedirect(url('/app/settings?tab=security&linked=roeid'));

        $identity = $user->identities()->sole();
        $this->assertSame('roeid', $identity->provider);
        $this->assertSame('subject-123', $identity->subject);

        // Self-service security event: audited with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.identity_linked',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_connect_intent_refuses_a_subject_linked_to_another_account(): void
    {
        $other = $this->createUser();
        $other->identities()->create(['provider' => 'roeid', 'subject' => 'subject-123']);
        $user = $this->createUser();

        $this->loginAndCarrySession($user);
        $flow = $this->startFlow('?intent=connect');
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)
            ->assertRedirect(url('/app/settings?tab=security&identity_error=taken'));

        $this->assertSame(0, $user->identities()->count());
        // The rightful owner's link is untouched.
        $this->assertSame(1, $other->identities()->count());
    }

    public function test_connect_intent_refuses_a_second_subject_for_the_same_provider(): void
    {
        $user = $this->createUser();
        $user->identities()->create(['provider' => 'roeid', 'subject' => 'older-subject']);

        $this->loginAndCarrySession($user);
        $flow = $this->startFlow('?intent=connect');
        $this->pendingIdToken = $this->idToken(['nonce' => $flow['nonce']]);

        $this->completeFlow($flow)
            ->assertRedirect(url('/app/settings?tab=security&identity_error=already_linked'));

        $this->assertSame('older-subject', $user->identities()->sole()->subject);
    }

    public function test_signed_in_user_without_connect_intent_is_sent_to_the_app(): void
    {
        $user = $this->createUser();

        $this->loginAndCarrySession($user);

        $this->get('/auth/roeid/redirect')->assertRedirect(url('/app'));
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * Hit the redirect endpoint and carry its session (state, nonce, PKCE
     * verifier) into the callback, like a browser would.
     *
     * @return array<string, string> the authorization query (state, nonce, ...)
     */
    /**
     * A user with a confirmed two-factor enrollment.
     *
     * Confirmation consumes the current time step, and tests cannot wait
     * out a 30-second window - so the replay guard is rewound to let the
     * current code answer the login challenge in this test run.
     */
    private function enrolledUser(): User
    {
        $user = $this->createUser()->refresh();

        $twoFactor = $this->app->make(\App\Services\Auth\TwoFactorService::class);
        $engine = $this->app->make(\PragmaRX\Google2FA\Google2FA::class);

        $enrollment = $twoFactor->startEnrollment($user);
        $twoFactor->confirmEnrollment($user, $engine->getCurrentOtp($enrollment->secret));

        $user->forceFill(['two_factor_last_verified_step' => $user->two_factor_last_verified_step - 2])->save();

        return $user;
    }

    private function startFlow(string $query = ''): array
    {
        $redirect = $this->get('/auth/roeid/redirect'.$query);
        $redirect->assertRedirect();

        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $sessionCookie = collect($redirect->headers->getCookies())
            ->first(fn(object $cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($sessionCookie);
        $this->withCredentials();
        $this->withUnencryptedCookie((string) config('session.cookie'), (string) $sessionCookie->getValue());

        return $query;
    }

    private function completeFlow(array $flow): TestResponse
    {
        // The Socialite manager caches driver instances, and a cached
        // provider holds the request it was built with. In production
        // every request is a fresh process; in-process test requests must
        // rebuild the driver so it sees the callback request.
        \Laravel\Socialite\Facades\Socialite::forgetDrivers();

        return $this->get('/auth/roeid/callback?code=fake-code&state='.urlencode($flow['state']));
    }

    /**
     * A signed ID token; overrides let each test bend one claim.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function idToken(array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://sso.test',
            'aud' => 'acme-client',
            'sub' => 'subject-123',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => 'unset',
        ], $overrides);

        return JWT::encode($claims, self::keyMaterial()['private'], 'RS256', 'test-key');
    }

    /**
     * One RSA key pair per process: the private key signs test ID tokens,
     * the JWKS the fake endpoint serves carries the public half.
     *
     * @return array{private: string, jwks: array<string, mixed>}
     */
    private static function keyMaterial(): array
    {
        static $material = null;

        if ($material !== null) {
            return $material;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);

        $encode = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return $material = [
            'private' => $privateKey,
            'jwks' => [
                'keys' => [
                    [
                        'kty' => 'RSA',
                        'alg' => 'RS256',
                        'use' => 'sig',
                        'kid' => 'test-key',
                        'n' => $encode($details['rsa']['n']),
                        'e' => $encode($details['rsa']['e']),
                    ]
                ],
            ],
        ];
    }
}
