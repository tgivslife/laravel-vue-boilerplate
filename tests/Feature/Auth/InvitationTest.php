<?php

namespace Tests\Feature\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The consume-side behavior of admin invitations: they ride the magic-link machinery but answer to
 * security.invitations, not the self-serve door switch.
 * The creation-side stamps (delivery modes, password gate, two-factor mandate) are covered by the access suite.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The consume limiter keys by IP, which is identical for every
        // test in this file; without a flush the counters bleed across tests.
        $this->app['cache']->flush();

        config(['security.invitations.enabled' => true]);

        Notification::fake();
    }

    /* ------------------------------------------------------------------ *
     *  Issuing
     * ------------------------------------------------------------------ */

    public function test_inviting_mints_a_day_scale_token_and_mails_it(): void
    {
        $user = $this->invitedUser();

        app(MagicLinkService::class)->invite($user);

        Notification::assertSentTo($user, InvitationNotification::class);

        $token = MagicLinkToken::query()->sole();
        $this->assertSame(MagicLinkToken::PURPOSE_INVITATION, $token->purpose);
        $this->assertSame($user->id, $token->user_id);
        $this->assertTrue($token->expires_at->isAfter(now()->addDays(6)));
    }

    public function test_a_resend_revokes_the_previous_invitation(): void
    {
        $user = $this->invitedUser();
        $older = $this->invite($user);
        $newer = $this->invite($user);

        // Exactly one live link: the older token's row is gone, not just superseded.
        $this->assertDatabaseCount('magic_link_tokens', 1);

        $this->consume($older)->assertStatus(401);
        $this->consume($newer)->assertStatus(200);
    }

    /* ------------------------------------------------------------------ *
     *  Consuming
     * ------------------------------------------------------------------ */

    public function test_consuming_an_invitation_signs_in_and_verifies_the_email(): void
    {
        $user = $this->invitedUser();
        $token = $this->invite($user);

        $this->consume($token)
            ->assertStatus(200)
            ->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertNotNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    public function test_consuming_an_invitation_records_the_invitation_method(): void
    {
        $user = $this->invitedUser();
        $token = $this->invite($user);

        $this->consume($token)->assertStatus(200);

        $latest = $user->authentications()->successful()->latest('login_at')->first();
        $this->assertSame('invitation', $latest->login_method);
    }

    public function test_invitations_survive_a_disabled_magic_link_door(): void
    {
        // A password-only deployment keeps the self-serve door closed; the
        // invitation must consume anyway, while login links still die.
        // The door is pinned open for the issue itself - send() is a no-op behind a closed one.
        config(['security.magic_link.enabled' => true]);
        $loginUser = $this->createUser();
        $loginToken = $this->issueLoginTokenFor($loginUser);

        $invited = $this->invitedUser();
        $inviteToken = $this->invite($invited);

        config(['security.magic_link.enabled' => false]);

        $this->consume($loginToken)->assertStatus(401);
        $this->assertGuest();

        $this->consume($inviteToken)->assertStatus(200);
        $this->assertAuthenticatedAs($invited);
    }

    public function test_disabling_invitations_kills_outstanding_links(): void
    {
        $user = $this->invitedUser();
        $token = $this->invite($user);

        config(['security.invitations.enabled' => false]);

        // Same indistinguishable outcome as an expired token; the claim spends it either way.
        $this->consume($token)->assertStatus(401);
        $this->assertGuest();
        $this->assertNotNull(MagicLinkToken::query()->sole()->consumed_at);
    }

    public function test_an_expired_invitation_is_rejected(): void
    {
        $user = $this->invitedUser();
        $token = $this->invite($user);

        $this->travel((int) config('security.invitations.ttl_days') + 1)->days();

        $this->consume($token)->assertStatus(401);
        $this->assertGuest();
    }

    public function test_an_invitation_cannot_be_consumed_twice(): void
    {
        $user = $this->invitedUser();
        $token = $this->invite($user);

        $this->consume($token)->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->consume($token)->assertStatus(401);
    }

    public function test_a_two_factor_enrolled_account_is_parked_for_the_challenge(): void
    {
        $user = $this->invitedUser([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $token = $this->invite($user);

        // The link proves the mailbox, not the second factor.
        $this->consume($token)
            ->assertStatus(200)
            ->assertJsonPath('data.two_factor_required', true);

        $this->assertGuest();
    }

    public function test_a_password_gated_invitee_sets_a_first_password_to_open_the_app(): void
    {
        // The stamps of a password-door-only deployment: passwordless, forced to choose one.
        $user = $this->invitedUser(['require_password_reset' => true]);
        $token = $this->invite($user);

        $this->consume($token)->assertStatus(200);
        $this->assertAuthenticatedAs($user);

        // Signed in but gated until the first password is chosen.
        $this->withHeader('Referer', config('app.url'))
            ->getJson('/api/sessions')
            ->assertStatus(403)
            ->assertJsonPath('title', __('api.auth.titles.password_reset_required'));

        // No current_password: the session that proved the mailbox is the confirmation.
        $this->withHeader('Referer', config('app.url'))
            ->putJson('/api/password', [
                'password' => 'chosen-passphrase-123',
                'password_confirmation' => 'chosen-passphrase-123',
            ])
            ->assertStatus(200);

        $this->withHeader('Referer', config('app.url'))
            ->getJson('/api/sessions')
            ->assertStatus(200);

        $this->assertFalse((bool) $user->refresh()->require_password_reset);
    }

    /* ------------------------------------------------------------------ *
     *  Helpers
     * ------------------------------------------------------------------ */

    /**
     * An account exactly as invitation-mode creation leaves it: passwordless and unverified.
     */
    private function invitedUser(array $attributes = []): User
    {
        return $this->createUser($attributes + [
                'password' => null,
                'email_verified_at' => null,
            ]);
    }

    /**
     * Invite the user through the service and return the plaintext token
     * extracted from the captured notification URL.
     */
    private function invite(User $user): string
    {
        app(MagicLinkService::class)->invite($user);

        $url = null;

        Notification::assertSentTo(
            $user,
            InvitationNotification::class,
            function (InvitationNotification $notification) use (&$url, $user): bool {
                $url = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertIsString($query['token'] ?? null);
        $this->assertSame('1', $query['invite'] ?? null);

        return $query['token'];
    }

    /**
     * Issue a self-serve login link for contrast with the invitation purpose.
     */
    private function issueLoginTokenFor(User $user): string
    {
        app(MagicLinkService::class)->send($user->email, null);

        $plaintext = null;

        Notification::assertSentTo(
            $user,
            MagicLinkNotification::class,
            function ($notification) use (&$plaintext, $user): bool {
                parse_str((string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);
                $plaintext = $query['token'] ?? null;

                return true;
            }
        );

        $this->assertIsString($plaintext);

        return $plaintext;
    }

    /**
     * Consume a token as the SPA would (stateful frontend origin).
     */
    private function consume(string $token): TestResponse
    {
        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/magic-link/consume', ['token' => $token]);
    }
}
