<?php

namespace Tests\Feature\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The magic-link limiters key by IP, which is identical for every
        // test in this file; without a flush the counters bleed across tests.
        $this->app['cache']->flush();

        // This suite covers the non-provisioning behavior (unknown emails are a silent no-op), so the provision
        // switch is pinned off regardless of the deployment default; MagicLinkProvisionTest owns the provision-on cases.
        config([
            'security.magic_link.enabled' => true,
            'security.magic_link.provision' => false,
        ]);

        Notification::fake();
    }

    /* ------------------------------------------------------------------ *
     *  Requesting a link
     * ------------------------------------------------------------------ */

    public function test_request_for_existing_user_queues_a_notification(): void
    {
        $user = $this->createUser();

        $response = $this->requestLink($user->email);

        $response->assertStatus(202);
        Notification::assertSentTo($user, MagicLinkNotification::class);
        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_request_for_unknown_email_returns_the_identical_response(): void
    {
        $user = $this->createUser();

        $known = $this->requestLink($user->email);
        $unknown = $this->requestLink('nobody@example.com');

        // Byte-for-byte identical body and status: an attacker probing the
        // endpoint cannot tell which emails have accounts.
        $unknown->assertStatus(202);
        $this->assertSame($known->getContent(), $unknown->getContent());

        Notification::assertCount(1);
    }

    public function test_request_for_deactivated_or_banned_user_sends_nothing(): void
    {
        $inactive = $this->createUser(['is_active' => false]);
        $banned = $this->createUser(['banned_at' => now(), 'ban_reason' => 'test']);

        $this->requestLink($inactive->email)->assertStatus(202);
        $this->requestLink($banned->email)->assertStatus(202);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_request_sends_nothing_when_the_feature_is_disabled(): void
    {
        config(['security.magic_link.enabled' => false]);
        $user = $this->createUser();

        $this->requestLink($user->email)->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_request_requires_a_valid_email(): void
    {
        $this->requestLink('not-an-email')->assertStatus(422);
    }

    public function test_request_is_throttled_per_email_and_ip(): void
    {
        config(['security.magic_link.request_limit.max_attempts' => 2]);
        $user = $this->createUser();

        $this->requestLink($user->email)->assertStatus(202);
        $this->requestLink($user->email)->assertStatus(202);

        $this->requestLink($user->email)->assertStatus(429);
    }

    public function test_safe_redirect_is_carried_into_the_emailed_link(): void
    {
        $user = $this->createUser();

        $this->requestLink($user->email, '/app/devices/phones?tab=active');

        $url = $this->capturedActionUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('/app/devices/phones?tab=active', $query['redirect']);
    }

    public function test_unsafe_redirects_are_dropped_from_the_emailed_link(): void
    {
        foreach (['https://evil.example', '//evil.example', '/\\evil.example'] as $redirect) {
            $user = $this->createUser();

            $this->requestLink($user->email, $redirect);

            $url = $this->capturedActionUrl($user);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertArrayNotHasKey('redirect', $query, "Redirect [{$redirect}] should have been dropped.");
        }
    }

    /* ------------------------------------------------------------------ *
     *  Consuming a link
     * ------------------------------------------------------------------ */

    public function test_consuming_a_valid_token_logs_the_user_in(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $response = $this->consume($token);

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', $user->email);
        $this->assertAuthenticatedAs($user);

        $this->assertNotNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    public function test_consuming_backfills_email_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $this->issueTokenFor($user);

        $this->consume($token)->assertStatus(200);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_a_token_cannot_be_consumed_twice(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->consume($token)->assertStatus(200);

        // Retry as a fresh, unauthenticated client (new browser, no session):
        // the claim must fail, not the already-authenticated guard.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->consume($token)
            ->assertStatus(401)
            ->assertJsonPath('title', __('api.auth.titles.invalid_magic_link'));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->travel((int) config('security.magic_link.ttl_minutes') + 1)->minutes();

        $this->consume($token)->assertStatus(401);
        $this->assertGuest();
    }

    public function test_an_unknown_token_is_rejected_with_the_same_error(): void
    {
        $response = $this->consume('this-token-never-existed');

        $response->assertStatus(401);
        $response->assertJsonPath('title', __('api.auth.titles.invalid_magic_link'));
        $this->assertGuest();
    }

    public function test_a_newer_link_does_not_invalidate_an_older_one(): void
    {
        // Deliberate: a delayed email must not strand the user. The TTL
        // bounds how long the older link stays live.
        $user = $this->createUser();
        $older = $this->issueTokenFor($user);
        $this->requestLink($user->email);

        $this->consume($older)->assertStatus(200);
    }

    public function test_a_deactivated_user_cannot_log_in_but_the_token_is_still_burned(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $user->forceFill(['is_active' => false])->save();

        $this->consume($token)
            ->assertStatus(403)
            ->assertJsonPath('title', __('api.auth.titles.account_deactivated'));

        $this->assertGuest();
        $this->assertNotNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    public function test_an_already_authenticated_user_does_not_burn_the_token(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->actingAs($user);

        $this->consume($token)->assertStatus(409);

        $this->assertNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    public function test_consume_without_a_frontend_origin_cannot_start_a_session(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        // No Referer header: Sanctum does not treat this as a stateful
        // frontend request, so no session store is attached. The Referer set
        // by issueTokenFor() persists in defaultHeaders, so drop it first.
        $this->flushHeaders();

        $response = $this->postJson('/api/magic-link/consume', ['token' => $token]);

        $response->assertStatus(400);
        $response->assertJsonPath('title', __('api.auth.titles.session_unavailable'));

        $this->assertNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    public function test_consume_requires_a_token(): void
    {
        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/magic-link/consume', [])
            ->assertStatus(422);
    }

    public function test_consume_is_throttled(): void
    {
        config(['security.magic_link.consume_limit.max_attempts' => 2]);

        $this->consume('wrong-token')->assertStatus(401);
        $this->consume('another-wrong-token')->assertStatus(401);

        $this->consume('third-wrong-token')->assertStatus(429);
    }

    public function test_disabling_the_feature_kills_outstanding_links(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        config(['security.magic_link.enabled' => false]);

        // Same indistinguishable outcome as an expired token.
        $this->consume($token)->assertStatus(401);
        $this->assertGuest();
    }

    public function test_consuming_a_link_records_the_magic_link_method(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->consume($token)->assertStatus(200);

        $latest = $user->authentications()->successful()->latest('login_at')->first();
        $this->assertSame('magic_link', $latest->login_method);
    }

    /* ------------------------------------------------------------------ *
     *  Maintenance
     * ------------------------------------------------------------------ */

    public function test_purge_command_deletes_expired_and_consumed_tokens_only(): void
    {
        MagicLinkToken::factory()->create();
        MagicLinkToken::factory()->expired()->create();
        MagicLinkToken::factory()->consumed()->create();

        $this->artisan('auth:purge-magic-link-tokens')->assertSuccessful();

        $this->assertDatabaseCount('magic_link_tokens', 1);
        $this->assertNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * Request a magic link as the SPA would (stateful frontend origin).
     */
    private function requestLink(string $email, ?string $redirect = null): TestResponse
    {
        $payload = ['email' => $email];

        if ($redirect !== null) {
            $payload['redirect'] = $redirect;
        }

        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/magic-link', $payload);
    }

    /**
     * Consume a magic-link token as the SPA would.
     */
    private function consume(string $token): TestResponse
    {
        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/magic-link/consume', ['token' => $token]);
    }

    /**
     * Issue a real token for the user through the HTTP endpoint and return
     * the plaintext extracted from the captured notification URL.
     */
    private function issueTokenFor(User $user): string
    {
        $this->requestLink($user->email)->assertStatus(202);

        parse_str((string) parse_url($this->capturedActionUrl($user), PHP_URL_QUERY), $query);

        $this->assertIsString($query['token'] ?? null);

        return $query['token'];
    }

    /**
     * Extract the action URL from the (faked) queued notification.
     */
    private function capturedActionUrl(User $user): string
    {
        $url = null;

        Notification::assertSentTo(
            $user,
            MagicLinkNotification::class,
            function (MagicLinkNotification $notification) use (&$url, $user): bool {
                $url = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($url);

        return $url;
    }
}
