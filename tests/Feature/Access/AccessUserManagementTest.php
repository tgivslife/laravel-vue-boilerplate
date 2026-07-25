<?php

namespace Tests\Feature\Access;

use App\Models\Access\AccessAuditLog;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Auth\MagicLinkService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class AccessUserManagementTest extends AccessTestCase
{
    /**
     * A signed-in admin (role holding the user-administration capabilities).
     */
    private function actingAsManager(): User
    {
        $role = config('permission.models.role')::findOrCreate('managers', config('access.guard'));
        $role->givePermissionTo(['users.view', 'users.manage']);

        $user = $this->createUser();
        $user->assignRole($role);

        $this->actingAsStateful($user);

        return $user;
    }

    public function test_account_management_requires_a_capability(): void
    {
        $user = $this->createUser();
        $target = $this->createUser();
        $this->actingAsStateful($user);

        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/access/users/{$target->id}")->assertStatus(403);
        $this->getJson("/api/access/users/{$target->id}/sessions")->assertStatus(403);
        $this->getJson("/api/access/users/{$target->id}/authentication-logs")->assertStatus(403);
        $this->getJson("/api/access/users/{$target->id}/audit-logs")->assertStatus(403);
    }

    public function test_account_name_is_updated_through_the_api(): void
    {
        $this->actingAsManager();
        $target = $this->createUser(['first_name' => 'Old', 'last_name' => 'Name']);

        $this->patchJson("/api/access/users/{$target->id}", [
            'first_name' => 'New',
            'last_name' => 'Person',
        ])->assertOk()
            ->assertJsonPath('data.user.first_name', 'New')
            ->assertJsonPath('data.user.last_name', 'Person');

        $this->assertSame('New', $target->fresh()->first_name);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user.account_updated']);
    }

    public function test_the_two_factor_mandate_is_toggled_through_the_account_update(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $this->patchJson("/api/access/users/{$target->id}", ['two_factor_required' => true])
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_required', true);

        $this->assertTrue($target->fresh()->two_factor_required);
        $this->assertTrue($target->fresh()->mustEnrollTwoFactor());
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user.account_updated']);

        $this->patchJson("/api/access/users/{$target->id}", ['two_factor_required' => false])
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_required', false);

        $this->assertFalse($target->fresh()->mustEnrollTwoFactor());
    }

    public function test_a_pending_invitation_is_resent_with_a_fresh_link(): void
    {
        Notification::fake();
        $this->actingAsManager();
        $target = $this->createUser(['password' => null, 'email_verified_at' => null]);
        app(MagicLinkService::class)->invite($target);
        $original = MagicLinkToken::query()->sole();

        $this->postJson("/api/access/users/{$target->id}/resend-invitation")
            ->assertOk()
            ->assertJsonPath('data.user.invitation_pending', true);

        // The previous link is revoked outright, not left to its TTL.
        $current = MagicLinkToken::query()->sole();
        $this->assertNotSame($original->token_hash, $current->token_hash);

        Notification::assertSentToTimes($target, InvitationNotification::class, 2);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user.invitation_resent']);
    }

    public function test_an_invitable_account_without_a_live_link_can_be_reinvited(): void
    {
        Notification::fake();
        $this->actingAsManager();

        // The link was burned while the account was deactivated (consume spends rejected tokens);
        // after reactivation the account is invitable again but holds no live invitation.
        $target = $this->createUser(['password' => null, 'email_verified_at' => null]);
        app(MagicLinkService::class)->invite($target);
        MagicLinkToken::query()->update(['consumed_at' => now()]);

        $response = $this->postJson("/api/access/users/{$target->id}/resend-invitation")->assertOk();

        $this->assertTrue($response->json('data.user.invitable'));
        $this->assertTrue($response->json('data.user.invitation_pending'));
        $this->assertNotNull(MagicLinkToken::query()->whereNull('consumed_at')->sole());
        Notification::assertSentToTimes($target, InvitationNotification::class, 2);
    }

    public function test_a_resend_is_refused_once_the_account_was_entered(): void
    {
        Notification::fake();
        $this->actingAsManager();

        // A verified email or a recorded login means the invitation did its job.
        $verified = $this->createUser();
        $this->postJson("/api/access/users/{$verified->id}/resend-invitation")->assertStatus(422);

        $loggedIn = $this->createUser(['email_verified_at' => null, 'last_login_at' => now()]);
        $this->postJson("/api/access/users/{$loggedIn->id}/resend-invitation")->assertStatus(422);

        $deactivated = $this->createUser(['email_verified_at' => null, 'is_active' => false]);
        $this->postJson("/api/access/users/{$deactivated->id}/resend-invitation")->assertStatus(422);

        // Temporary-password onboarding: the consumed link would strand its user in
        // front of a current-password prompt for a password they were never told.
        $tempOnboarded = $this->createUser(['email_verified_at' => null]);
        $this->postJson("/api/access/users/{$tempOnboarded->id}/resend-invitation")->assertStatus(422);

        Notification::assertNothingSent();

        // Disabled feature: the door does not exist.
        config(['security.invitations.enabled' => false]);
        $fresh = $this->createUser(['email_verified_at' => null, 'password' => null]);
        $this->postJson("/api/access/users/{$fresh->id}/resend-invitation")->assertStatus(404);
    }

    public function test_a_forced_password_reset_revokes_a_pending_invitation(): void
    {
        Notification::fake();
        $this->actingAsManager();
        $target = $this->createUser(['password' => null, 'email_verified_at' => null]);
        app(MagicLinkService::class)->invite($target);

        // The admin switched onboarding modes: the invitation's link dies with it.
        $response = $this->postJson("/api/access/users/{$target->id}/force-password-reset")->assertOk();

        $this->assertFalse($response->json('data.user.invitation_pending'));
        $this->assertDatabaseMissing('magic_link_tokens', ['user_id' => $target->id]);
    }

    public function test_the_invited_state_ends_with_invitability_even_while_a_link_is_live(): void
    {
        Notification::fake();
        $this->actingAsManager();
        $target = $this->createUser(['password' => null, 'email_verified_at' => null]);
        app(MagicLinkService::class)->invite($target);

        // Marking the email verified ends the invited window; the link itself stays
        // consumable (it is still the legitimate first sign-in), only the state moves on.
        $this->patchJson("/api/access/users/{$target->id}", ['email_verified' => true])
            ->assertOk()
            ->assertJsonPath('data.user.invitation_pending', false);

        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_an_admin_two_factor_reset_clears_the_factor_and_notifies_the_owner(): void
    {
        Notification::fake();
        $this->actingAsManager();

        $twoFactor = $this->app->make(\App\Services\Auth\TwoFactorService::class);
        $engine = $this->app->make(\PragmaRX\Google2FA\Google2FA::class);

        $target = $this->createUser();
        $enrollment = $twoFactor->startEnrollment($target);
        $twoFactor->confirmEnrollment($target, $engine->getCurrentOtp($enrollment->secret));

        $this->deleteJson("/api/access/users/{$target->id}/two-factor")
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_enabled', false);

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertFalse($target->hasTwoFactorEnabled());

        $this->assertSame(1, AccessAuditLog::where('action', 'user.two_factor_reset')->count());
        Notification::assertSentTo($target, \App\Notifications\TwoFactorDisabledNotification::class);

        // Resetting an account with nothing enrolled is a no-op: no fresh audit entry, no second mail.
        $this->deleteJson("/api/access/users/{$target->id}/two-factor")->assertOk();
        $this->assertSame(1, AccessAuditLog::where('action', 'user.two_factor_reset')->count());
        Notification::assertSentToTimes($target, \App\Notifications\TwoFactorDisabledNotification::class, 1);

        // The reset is gated by the same kill switch as the self-service endpoints: with the feature off, the door does not exist.
        config(['security.two_factor.enabled' => false]);
        $this->deleteJson("/api/access/users/{$target->id}/two-factor")->assertStatus(404);
    }

    public function test_an_email_can_be_marked_verified(): void
    {
        $this->actingAsManager();
        $target = $this->createUser(['email_verified_at' => null]);

        $this->patchJson("/api/access/users/{$target->id}", ['email_verified' => true])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', true);

        $this->assertTrue($target->fresh()->hasVerifiedEmail());
    }

    public function test_an_account_can_be_deactivated_and_reactivated(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $this->patchJson("/api/access/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.user.is_active', false);
        $this->assertFalse((bool) $target->fresh()->is_active);

        $this->patchJson("/api/access/users/{$target->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.user.is_active', true);
        $this->assertTrue((bool) $target->fresh()->is_active);
    }

    public function test_deactivating_the_last_active_manager_is_refused(): void
    {
        $actor = $this->actingAsManager();

        $this->patchJson("/api/access/users/{$actor->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'access');

        $this->assertTrue((bool) $actor->fresh()->is_active);
    }

    public function test_an_account_can_be_banned_with_a_reason_and_unbanned(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $this->patchJson("/api/access/users/{$target->id}", ['banned' => true, 'ban_reason' => 'Abuse'])
            ->assertOk()
            ->assertJsonPath('data.user.ban_reason', 'Abuse');

        $banned = $target->fresh();
        $this->assertNotNull($banned->banned_at);
        $this->assertFalse($banned->canAuthenticate());

        // Lifting the ban clears both the timestamp and the reason.
        $this->patchJson("/api/access/users/{$target->id}", ['banned' => false])
            ->assertOk()
            ->assertJsonPath('data.user.banned_at', null)
            ->assertJsonPath('data.user.ban_reason', null);

        $this->assertTrue($target->fresh()->canAuthenticate());
    }

    public function test_the_ban_reason_can_be_updated_on_its_own_while_the_ban_stays_in_place(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $this->patchJson("/api/access/users/{$target->id}", ['banned' => true, 'ban_reason' => 'Abuse'])->assertOk();
        $bannedAt = $target->fresh()->banned_at;

        $this->patchJson("/api/access/users/{$target->id}", ['ban_reason' => 'Spam'])
            ->assertOk()
            ->assertJsonPath('data.user.ban_reason', 'Spam');

        $banned = $target->fresh();
        $this->assertSame('Spam', $banned->ban_reason);
        $this->assertEquals($bannedAt, $banned->banned_at);
    }

    public function test_the_ban_reason_can_be_cleared_with_an_explicit_null_while_the_ban_stays_in_place(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $this->patchJson("/api/access/users/{$target->id}", ['banned' => true, 'ban_reason' => 'Abuse'])->assertOk();

        $this->patchJson("/api/access/users/{$target->id}", ['banned' => true, 'ban_reason' => null])
            ->assertOk()
            ->assertJsonPath('data.user.ban_reason', null);

        $banned = $target->fresh();
        $this->assertNull($banned->ban_reason);
        $this->assertNotNull($banned->banned_at);
    }

    public function test_a_ban_reason_sent_for_an_unbanned_account_is_ignored(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $this->patchJson("/api/access/users/{$target->id}", ['ban_reason' => 'Abuse'])
            ->assertOk()
            ->assertJsonPath('data.user.ban_reason', null);

        $fresh = $target->fresh();
        $this->assertNull($fresh->ban_reason);
        $this->assertNull($fresh->banned_at);
    }

    public function test_banning_the_last_active_manager_is_refused(): void
    {
        $actor = $this->actingAsManager();

        $this->patchJson("/api/access/users/{$actor->id}", ['banned' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'access');

        $this->assertNull($actor->fresh()->banned_at);
    }

    public function test_a_password_reset_is_forced_with_a_generated_temporary_password(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $sessionId = $this->createOtherSessionFor($target);

        $response = $this->postJson("/api/access/users/{$target->id}/force-password-reset")
            ->assertOk()
            ->assertJsonPath('data.user.require_password_reset', true);

        // The server generates the password and returns it exactly once.
        $temporaryPassword = $response->json('data.temporary_password');
        $this->assertSame(16, strlen($temporaryPassword));

        $target = $target->fresh();
        $this->assertTrue($target->require_password_reset);
        $this->assertTrue(Hash::check($temporaryPassword, $target->password));
        // The old credential stops working everywhere at once.
        $this->assertDatabaseMissing('user_sessions', ['session_id' => $sessionId]);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user.password_reset_forced']);
    }

    public function test_the_temporary_password_never_reaches_the_audit_trail(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $temporaryPassword = $this->postJson("/api/access/users/{$target->id}/force-password-reset")
            ->json('data.temporary_password');

        $audit = AccessAuditLog::where('action', 'user.password_reset_forced')->first();
        $this->assertStringNotContainsString($temporaryPassword, json_encode($audit->getAttributes()));
    }

    public function test_the_forced_reset_flag_cannot_be_changed_through_patch(): void
    {
        $this->actingAsManager();
        $target = $this->createUser(['require_password_reset' => true]);

        // The requirement is one-way: forcing goes through the dedicated
        // endpoint, and only the user clears it by changing their password.
        $this->patchJson("/api/access/users/{$target->id}", ['require_password_reset' => true])
            ->assertStatus(422);
        $this->patchJson("/api/access/users/{$target->id}", ['require_password_reset' => false])
            ->assertStatus(422);

        $this->assertTrue($target->fresh()->require_password_reset);
    }

    public function test_invalid_account_payloads_fail_validation(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => str_repeat('a', 300)])->assertStatus(422);
        $this->patchJson("/api/access/users/{$target->id}", ['email_verified' => 'banana'])->assertStatus(422);
    }

    public function test_an_account_is_deleted_with_its_credentials(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $originalEmail = $target->email;
        $target->createToken('cli');
        $target->identities()->create(['provider' => 'roeid', 'subject' => 'subject-1']);
        // Soft deletion never fires the FK cascade, so retirement must sweep these itself.
        MagicLinkToken::factory()->for($target)->create();
        MagicLinkToken::factory()->invitation()->for($target)->create();
        $sessionId = $this->createOtherSessionFor($target);

        $this->deleteJson("/api/access/users/{$target->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('magic_link_tokens', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('user_sessions', ['session_id' => $sessionId]);
        $this->assertDatabaseMissing('user_identities', ['user_id' => $target->id]);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user.deleted']);

        // The address is tombstoned out of the unique index (it can become an account again), while membership stays answerable via the keyed hash.
        $deleted = User::withTrashed()->find($target->id);
        $this->assertStringEndsWith('@deleted.invalid', $deleted->email);
        $this->assertSame($deleted->id, User::onlyTrashed()->whereDeletedEmail($originalEmail)->sole()->id);
    }

    public function test_audit_actors_survive_their_own_deletion(): void
    {
        $adminA = $this->actingAsManager();
        $target = $this->createUser();

        $this->deleteJson("/api/access/users/{$target->id}")->assertOk();

        // A second manager retires admin A - as a fresh client, or the stateful
        // session would keep acting as A and trip the self-delete refusal.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAsManager();
        $this->deleteJson("/api/access/users/{$adminA->id}")->assertOk();

        $entries = $this->getJson("/api/access/users/{$target->id}/audit-logs")
            ->assertOk()
            ->json('data.entries');

        $entry = collect($entries)->firstWhere('action', 'user.deleted');
        $this->assertSame($adminA->first_name, $entry['actor']['first_name']);
        $this->assertTrue($entry['actor']['deleted']);
    }

    public function test_audit_purge_deletes_only_entries_past_retention(): void
    {
        config(['access.audit_log.retention_days' => 730]);
        $actor = $this->createUser();

        $old = $this->auditEntryFor($actor, now()->subDays(800));
        $this->auditEntryFor($actor, now()->subDays(10));

        $this->artisan('access:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseCount('access_audit_logs', 1);
        $this->assertDatabaseMissing('access_audit_logs', ['id' => $old->id]);
    }

    public function test_non_positive_audit_retention_keeps_entries_forever(): void
    {
        config(['access.audit_log.retention_days' => 0]);
        $actor = $this->createUser();

        $this->auditEntryFor($actor, now()->subYears(10));

        $this->artisan('access:purge-audit-logs')->assertSuccessful();

        $this->assertDatabaseCount('access_audit_logs', 1);
    }

    /**
     * Write an audit entry backdated to the given moment (created_at is not
     * mass assignable, and the model has no updated_at to disturb).
     */
    private function auditEntryFor(User $actor, \Carbon\CarbonInterface $createdAt): AccessAuditLog
    {
        $entry = AccessAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => 'user.account_updated',
            'subject_type' => $actor->getMorphClass(),
            'subject_id' => $actor->id,
            'before' => null,
            'after' => null,
            'ip_address' => null,
        ]);

        $entry->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $entry;
    }

    public function test_self_deletion_is_refused(): void
    {
        $admin = $this->actingAsManager();

        $this->deleteJson("/api/access/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'access');

        $this->assertNull($admin->fresh()->deleted_at);
    }

    public function test_a_users_sessions_are_listed(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $this->createOtherSessionFor($target);

        $response = $this->getJson("/api/access/users/{$target->id}/sessions")->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('198.51.100.7', $response->json('data.sessions.0.ip_address'));
        $this->assertFalse($response->json('data.sessions.0.is_current'));
    }

    public function test_a_users_authentication_log_is_listed(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $target->authentications()->create([
            'ip_address' => '203.0.113.9',
            'device_name' => 'Windows 10 / Chrome 120.0',
            'login_at' => now()->subHour(),
            'login_successful' => true,
        ]);

        $response = $this->getJson("/api/access/users/{$target->id}/authentication-logs")->assertOk();

        $this->assertCount(1, $response->json('data.entries'));
        $this->assertSame('203.0.113.9', $response->json('data.entries.0.ip_address'));
        $this->assertFalse($response->json('data.has_more'));
    }

    public function test_no_op_mutations_write_no_audit_entries(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $this->putJson("/api/access/users/{$target->id}/roles", ['role_ids' => [$editors->getKey()]])->assertOk();

        // Re-sending the same grants and an unchanged PATCH record nothing.
        $this->putJson("/api/access/users/{$target->id}/roles", ['role_ids' => [$editors->getKey()]])->assertOk();
        $this->putJson("/api/access/users/{$target->id}/permissions", ['permission_ids' => []])->assertOk();
        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => $target->first_name])->assertOk();

        $response = $this->getJson("/api/access/users/{$target->id}/audit-logs")->assertOk();

        $this->assertCount(1, $response->json('data.entries'));
        $this->assertSame('user.roles_synced', $response->json('data.entries.0.action'));
    }

    public function test_a_users_audit_trail_is_listed_newest_first_and_scoped_to_the_subject(): void
    {
        $actor = $this->actingAsManager();
        $target = $this->createUser();
        $other = $this->createUser();

        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'Renamed'])->assertOk();
        $this->patchJson("/api/access/users/{$target->id}", ['banned' => true, 'ban_reason' => 'Abuse'])->assertOk();
        $this->patchJson("/api/access/users/{$other->id}", ['first_name' => 'Elsewhere'])->assertOk();

        $response = $this->getJson("/api/access/users/{$target->id}/audit-logs")->assertOk();

        $this->assertCount(2, $response->json('data.entries'));
        $this->assertFalse($response->json('data.has_more'));

        // Newest first: the ban is the latest entry; the other user's mutation is absent.
        $this->assertSame('user.account_updated', $response->json('data.entries.0.action'));
        $this->assertSame($actor->id, $response->json('data.entries.0.actor.id'));
        $this->assertSame('Abuse', $response->json('data.entries.0.after.ban_reason'));
        $this->assertFalse($response->json('data.entries.0.before.banned'));
        $this->assertTrue($response->json('data.entries.0.after.banned'));

        $this->assertSame('Renamed', $response->json('data.entries.1.after.first_name'));
    }
}
