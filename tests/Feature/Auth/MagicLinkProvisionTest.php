<?php

namespace Tests\Feature\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Access\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Magic-link self-provisioning: with `security.magic_link.provision` on,
 * a link requested for an unknown email becomes a signup link whose consumption creates the account.
 */
class MagicLinkProvisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The magic-link limiters key by IP, which is identical for every
        // test in this file; without a flush the counters bleed across tests.
        $this->app['cache']->flush();

        config([
            'security.magic_link.enabled' => true,
            'security.magic_link.provision' => true,
        ]);

        Notification::fake();
    }

    /* ------------------------------------------------------------------ *
     *  Requesting a link
     * ------------------------------------------------------------------ */

    public function test_request_for_unknown_email_mints_a_provisioning_token_but_no_account(): void
    {
        $this->requestLink('newcomer@example.com')->assertStatus(202);

        $token = MagicLinkToken::query()->sole();
        $this->assertNull($token->user_id);
        $this->assertSame('newcomer@example.com', $token->email);

        // The account only comes to exist when the mailbox owner clicks.
        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);

        Notification::assertSentOnDemand(
            MagicLinkNotification::class,
            static fn(
                MagicLinkNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable
            ): bool => ($notifiable->routes['mail'] ?? null) === 'newcomer@example.com'
        );
    }

    public function test_request_response_is_identical_for_known_and_unknown_emails(): void
    {
        $user = $this->createUser();

        $known = $this->requestLink($user->email);
        $unknown = $this->requestLink('newcomer@example.com');

        $unknown->assertStatus(202);
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    public function test_provisioning_mail_carries_the_welcome_copy(): void
    {
        $this->requestLink('newcomer@example.com')->assertStatus(202);

        Notification::assertSentOnDemand(
            MagicLinkNotification::class,
            static function (
                MagicLinkNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable
            ): bool {
                return $notification->toMail($notifiable)->subject === __('api.auth.magic_link.mail.welcome_subject');
            }
        );
    }

    public function test_request_for_existing_user_still_mints_an_ordinary_token(): void
    {
        $user = $this->createUser();

        $this->requestLink($user->email)->assertStatus(202);

        Notification::assertSentTo($user, MagicLinkNotification::class);

        $token = MagicLinkToken::query()->sole();
        $this->assertSame($user->id, $token->user_id);
        $this->assertNull($token->email);
    }

    public function test_request_for_a_deactivated_account_still_sends_nothing(): void
    {
        // Provisioning must not turn an unusable existing account into a working door.
        $inactive = $this->createUser(['is_active' => false]);

        $this->requestLink($inactive->email)->assertStatus(202);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_flag_off_keeps_unknown_emails_a_silent_noop(): void
    {
        config(['security.magic_link.provision' => false]);

        $this->requestLink('newcomer@example.com')->assertStatus(202);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('magic_link_tokens', 0);
        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);
    }

    /* ------------------------------------------------------------------ *
     *  Consuming a link
     * ------------------------------------------------------------------ */

    public function test_consuming_a_provisioning_token_creates_the_account_and_signs_in(): void
    {
        $token = $this->issueProvisioningToken('ana.pop@example.com');

        $response = $this->consume($token);

        $response->assertStatus(200);
        $response->assertJsonPath('data.provisioned', true);

        $user = User::query()->where('email', 'ana.pop@example.com')->sole();
        $this->assertAuthenticatedAs($user);

        // Name fallbacks: the email local part, and a placeholder surname.
        $this->assertSame('ana.pop', $user->first_name);
        $this->assertSame('-', $user->last_name);

        // The consumed link proved the mailbox; the account is passwordless until its owner sets one.
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);

        // Deny-by-default: authenticated but authorized to nothing.
        $this->assertCount(0, $user->roles);

        // Self-provisioning is a new way into an account: audited with the owner as actor.
        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.self_provisioned',
            'actor_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_configured_default_roles_are_assigned_at_creation(): void
    {
        config(['access.self_provision_roles' => ['member', 'beta']]);
        $roleModel = config('permission.models.role');
        $roleModel::findOrCreate('member', config('access.guard'));
        $roleModel::findOrCreate('beta', config('access.guard'));

        $token = $this->issueProvisioningToken('newcomer@example.com');
        $this->consume($token)->assertStatus(200);

        $user = User::query()->where('email', 'newcomer@example.com')->sole();
        $this->assertEqualsCanonicalizing(['member', 'beta'], $user->roles->pluck('name')->all());
    }

    public function test_unseeded_default_roles_fail_provisioning_loudly(): void
    {
        // A listed-but-missing role is a deployment misconfiguration: fail, rather
        // than silently create an empty role that grants nothing. The 500's exception
        // report is that loud failure; the spy keeps the expected entry out of laravel.log.
        Log::spy();
        config(['access.self_provision_roles' => ['member']]);

        $token = $this->issueProvisioningToken('newcomer@example.com');

        $this->consume($token)->assertStatus(500);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);
    }

    public function test_an_ordinary_login_carries_no_provisioned_flag(): void
    {
        $user = $this->createUser();
        $token = $this->issueTokenFor($user);

        $this->consume($token)
            ->assertStatus(200)
            ->assertJsonMissingPath('data.provisioned');
    }

    public function test_an_account_created_after_the_send_is_signed_into_not_duplicated(): void
    {
        $token = $this->issueProvisioningToken('newcomer@example.com');

        $existing = $this->createUser(['email' => 'newcomer@example.com']);

        $response = $this->consume($token);

        // The link still proved the mailbox, so it signs into the account that now owns it.
        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.provisioned');
        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::query()->where('email', 'newcomer@example.com')->count());
    }

    public function test_disabling_provisioning_kills_outstanding_provisioning_links(): void
    {
        $token = $this->issueProvisioningToken('newcomer@example.com');

        config(['security.magic_link.provision' => false]);

        // Same indistinguishable outcome as an expired token, and no account.
        $this->consume($token)->assertStatus(401);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);
    }

    public function test_an_expired_provisioning_token_creates_nothing(): void
    {
        $token = $this->issueProvisioningToken('newcomer@example.com');

        $this->travel((int) config('security.magic_link.ttl_minutes') + 1)->minutes();

        $this->consume($token)->assertStatus(401);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);
    }

    public function test_a_deleted_accounts_email_can_be_provisioned_fresh(): void
    {
        // Deletion tombstones the email out of the unique index, so the
        // address is free to become a brand-new account.
        $admin = $this->createUser();
        $old = $this->createUser(['email' => 'newcomer@example.com']);
        app(AccessControlService::class)->deleteUser($admin, $old);

        $token = $this->issueProvisioningToken('newcomer@example.com');

        $this->consume($token)
            ->assertStatus(200)
            ->assertJsonPath('data.provisioned', true);

        $fresh = User::query()->where('email', 'newcomer@example.com')->sole();
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_the_provisioned_email_is_normalized(): void
    {
        // The request boundary lowercases, so the mail routes to the normalized
        // address whatever the user typed - and the created account stores it
        // lowercased, so no case-variant duplicate can ever be provisioned.
        $token = $this->issueProvisioningToken('newcomer@example.com', typed: 'NewComer@Example.COM');

        $this->consume($token)->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'newcomer@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'NewComer@Example.COM']);
    }

    public function test_the_enrollment_mandate_stamps_new_accounts_when_configured(): void
    {
        config(['security.magic_link.provision_two_factor_required' => true]);

        $token = $this->issueProvisioningToken('newcomer@example.com');
        $this->consume($token)->assertStatus(200);

        $user = User::query()->where('email', 'newcomer@example.com')->sole();
        $this->assertTrue($user->two_factor_required);
        $this->assertTrue($user->mustEnrollTwoFactor());
    }

    public function test_the_enrollment_mandate_never_touches_an_existing_account(): void
    {
        config(['security.magic_link.provision_two_factor_required' => true]);

        $token = $this->issueProvisioningToken('newcomer@example.com');
        $existing = $this->createUser(['email' => 'newcomer@example.com']);

        $this->consume($token)->assertStatus(200);

        // The mandate is a birth fact of provisioned accounts, not a side
        // effect of signing into one that already existed.
        $this->assertFalse($existing->refresh()->two_factor_required);
    }

    public function test_only_provisioning_links_carry_the_signup_marker(): void
    {
        $url = $this->capturedProvisioningUrl('newcomer@example.com');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('1', $query['signup'] ?? null);

        $user = $this->createUser();
        $this->requestLink($user->email)->assertStatus(202);

        $ordinaryUrl = null;
        Notification::assertSentTo(
            $user,
            MagicLinkNotification::class,
            static function (MagicLinkNotification $notification) use (&$ordinaryUrl, $user): bool {
                $ordinaryUrl = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        parse_str((string) parse_url((string) $ordinaryUrl, PHP_URL_QUERY), $ordinaryQuery);
        $this->assertArrayNotHasKey('signup', $ordinaryQuery);
    }

    public function test_the_signup_marker_coexists_with_the_redirect(): void
    {
        $url = $this->capturedProvisioningUrl('newcomer@example.com', '/app/reports?tab=daily');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('1', $query['signup'] ?? null);
        $this->assertSame('/app/reports?tab=daily', $query['redirect'] ?? null);
    }

    public function test_a_provisioning_token_cannot_be_consumed_twice(): void
    {
        $token = $this->issueProvisioningToken('newcomer@example.com');

        $this->consume($token)->assertStatus(200);

        // Retry as a fresh, unauthenticated client: the claim must fail and
        // must not create a second account.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->consume($token)->assertStatus(401);
        $this->assertSame(1, User::query()->where('email', 'newcomer@example.com')->count());
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
     * Issue a provisioning token for an unknown email through the HTTP endpoint
     * and return the plaintext extracted from the captured on-demand notification.
     */
    private function issueProvisioningToken(string $email, ?string $typed = null): string
    {
        parse_str((string) parse_url($this->capturedProvisioningUrl($email, typed: $typed), PHP_URL_QUERY), $query);

        $this->assertIsString($query['token'] ?? null);

        return $query['token'];
    }

    /**
     * Request a provisioning link and return the action URL captured from the
     * on-demand notification routed to the given address.
     *
     * `$typed` requests the link under a different spelling than the expected mail
     * route, for covering the request-boundary email normalization.
     */
    private function capturedProvisioningUrl(string $email, ?string $redirect = null, ?string $typed = null): string
    {
        $this->requestLink($typed ?? $email, $redirect)->assertStatus(202);

        $url = null;

        Notification::assertSentOnDemand(
            MagicLinkNotification::class,
            static function (MagicLinkNotification $notification, array $channels, AnonymousNotifiable $notifiable) use
            (
                &$url,
                $email
            ): bool {
                if (($notifiable->routes['mail'] ?? null) !== $email) {
                    return false;
                }

                $url = $notification->toMail($notifiable)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($url);

        return $url;
    }

    /**
     * Issue an ordinary token for an existing user through the HTTP endpoint.
     */
    private function issueTokenFor(User $user): string
    {
        $this->requestLink($user->email)->assertStatus(202);

        $url = null;

        Notification::assertSentTo(
            $user,
            MagicLinkNotification::class,
            static function (MagicLinkNotification $notification) use (&$url, $user): bool {
                $url = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        $this->assertIsString($url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertIsString($query['token'] ?? null);

        return $query['token'];
    }
}
