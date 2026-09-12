<?php

namespace Tests\Feature\Access;

use App\Http\Middleware\EnsureUserCanAuthenticate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\UserAllowlistDimension;

class ImpersonationTest extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['access.impersonation.enabled' => true]);
        UserAllowlistDimension::$visible = [];
    }

    /**
     * A signed-in holder of users.impersonate (and nothing else - the capability stands alone).
     */
    private function actingAsImpersonator(): User
    {
        $actor = $this->userWithPermissions('users.impersonate');
        $this->actingAsStateful($actor);

        return $actor;
    }

    private function actingAsSuperAdmin(): User
    {
        $role = config('permission.models.role')::findOrCreate(
            config('access.super_admin_role'), config('access.guard')
        );

        $actor = $this->createUser();
        $actor->assignRole($role);
        $this->actingAsStateful($actor);

        return $actor;
    }

    /**
     * Re-resolve the authenticated user on the next request. Every request in a test shares one
     * booted app, so the auth manager's guards keep answering with the identity they cached before
     * the swap - a test-runtime artifact; real deployments resolve guards fresh per request.
     */
    private function refreshGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_impersonation_swaps_the_session_to_the_target(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.impersonation.actor_id', $actor->id);
        $this->refreshGuards();

        // The whole session now answers as the target, banner state included.
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.impersonation.actor_name', trim($actor->first_name.' '.$actor->last_name));

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_started',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_stopping_restores_the_actor(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $this->deleteJson('/api/impersonation')
            ->assertOk()
            ->assertJsonPath('data.id', $actor->id)
            ->assertJsonPath('data.impersonation', null);
        $this->refreshGuards();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $actor->id);

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_the_start_endpoint_does_not_exist_while_the_feature_is_off(): void
    {
        config(['access.impersonation.enabled' => false]);

        $target = $this->createUser();
        $this->actingAsImpersonator();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertNotFound();

        // The way out is marker-gated, not switch-gated: without a marker it answers 422, never
        // 404 - so it cannot vanish under a live impersonation.
        $this->deleteJson('/api/impersonation')->assertUnprocessable();
    }

    public function test_switching_the_feature_off_does_not_strand_a_live_impersonation(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        config(['access.impersonation.enabled' => false]);

        $this->deleteJson('/api/impersonation')
            ->assertOk()
            ->assertJsonPath('data.id', $actor->id);

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_impersonation_requires_the_capability(): void
    {
        $target = $this->createUser();
        $this->actingAsStateful($this->userWithPermissions('users.view', 'users.manage'));

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertForbidden();
    }

    public function test_a_bearer_token_cannot_start_impersonation(): void
    {
        $target = $this->createUser();
        $this->actingAsStateless($this->userWithPermissions('users.impersonate'));

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertForbidden();
    }

    public function test_self_impersonation_is_refused(): void
    {
        $actor = $this->actingAsImpersonator();

        $this->postJson("/api/access/users/{$actor->id}/impersonate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.name', 'user');
    }

    public function test_an_account_that_cannot_authenticate_is_refused(): void
    {
        $this->actingAsImpersonator();
        $deactivated = $this->createUser(['is_active' => false]);

        $this->postJson("/api/access/users/{$deactivated->id}/impersonate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.name', 'user');
    }

    public function test_a_deleted_account_is_refused(): void
    {
        $this->actingAsImpersonator();
        $tombstoned = $this->createUser();
        $tombstoned->delete();

        $this->postJson("/api/access/users/{$tombstoned->id}/impersonate")->assertNotFound();
    }

    public function test_targets_above_the_actor_tier_are_refused(): void
    {
        $this->actingAsImpersonator();

        $lockoutHolder = $this->userWithPermissions('users.manage');
        $this->postJson("/api/access/users/{$lockoutHolder->id}/impersonate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.name', 'user');

        $superAdmin = $this->createUser();
        $superAdmin->assignRole(config('permission.models.role')::findOrCreate(
            config('access.super_admin_role'), config('access.guard')
        ));
        $this->postJson("/api/access/users/{$superAdmin->id}/impersonate")
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.name', 'user');
    }

    /**
     * Pins the strict-tier decision: admin-tier targets are reachable, but only from the
     * super-admin tier.
     */
    public function test_a_super_admin_may_impersonate_an_access_administrator(): void
    {
        $this->actingAsSuperAdmin();
        $admin = $this->userWithPermissions('users.manage', 'roles.manage');

        $this->postJson("/api/access/users/{$admin->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);
    }

    public function test_scope_dimensions_veto_out_of_reach_targets(): void
    {
        config(['access.dimensions' => [UserAllowlistDimension::class]]);

        $actor = $this->actingAsImpersonator();
        $inReach = $this->createUser();
        $outOfReach = $this->createUser();
        UserAllowlistDimension::$visible = [$actor->id => [$inReach->id]];

        $this->postJson("/api/access/users/{$outOfReach->id}/impersonate")->assertNotFound();
        $this->postJson("/api/access/users/{$inReach->id}/impersonate")->assertOk();
    }

    public function test_identity_critical_surfaces_are_closed_while_impersonating(): void
    {
        // A super-admin actor: the target's users.impersonate grant is privileged, so only the
        // super-admin tier reaches them - and every surface below belongs to the borrowed identity.
        $this->actingAsSuperAdmin();
        // The target holds browsing rights of their own, proving the block is the marker, not a
        // missing capability - and doubling as the no-nesting case on the impersonate endpoint.
        $target = $this->userWithPermissions('users.view', 'users.impersonate');
        $bystander = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $blocked = __('api.access.impersonation_blocked');

        $this->getJson('/api/access/users')
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->postJson("/api/access/users/{$bystander->id}/impersonate")
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->postJson('/api/tokens', ['name' => 'x'])
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->putJson('/api/password', [])
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->patchJson('/api/profile', ['first_name' => 'X', 'last_name' => 'Y'])
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->deleteJson('/api/sessions/others', ['password' => 'password'])
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->deleteJson('/api/sessions/'.str_repeat('a', 64))
            ->assertForbidden()->assertJsonPath('detail', $blocked);
        $this->deleteJson('/api/account')
            ->assertForbidden()->assertJsonPath('detail', $blocked);
    }

    /**
     * The OIDC connect flow is a credential surface like any other: linking an identity to the
     * target would hand the impersonator a persistent way in, audited as the owner's own doing.
     */
    public function test_the_oidc_connect_flow_is_refused_while_impersonating(): void
    {
        $this->enableOidcProvider();

        $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $this->get('/auth/roeid/redirect?intent=connect')
            ->assertRedirect('/app/settings?tab=security&identity_error=impersonating')
            ->assertSessionMissing('oidc_intent');

        $this->assertSame(0, $target->identities()->count());
    }

    /**
     * A `connect` intent parked before the swap survives it (impersonation regenerates the session
     * id, not the data), so the callback must refuse on its own - before the code exchange.
     */
    public function test_a_connect_intent_parked_before_the_swap_cannot_complete(): void
    {
        $this->enableOidcProvider();

        $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $this->withSession(['oidc_intent' => 'connect'])
            ->get('/auth/roeid/callback')
            ->assertRedirect('/app/settings?tab=security&identity_error=impersonating');

        $this->assertSame(0, $target->identities()->count());
    }

    private function enableOidcProvider(): void
    {
        config([
            'security.identity_providers.enabled' => true,
            'security.identity_providers.providers.roeid.enabled' => true,
            'services.roeid.issuer' => 'https://sso.test',
            'services.roeid.client_id' => 'acme-client',
            'services.roeid.client_secret' => 'client-secret',
            'services.roeid.redirect' => '/auth/roeid/callback',
        ]);
    }

    public function test_the_escape_hatch_frees_a_session_trapped_by_a_forced_reset(): void
    {
        $actor = $this->actingAsImpersonator();
        $trapped = $this->createUser(['require_password_reset' => true]);

        $this->postJson("/api/access/users/{$trapped->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $this->deleteJson('/api/impersonation')
            ->assertOk()
            ->assertJsonPath('data.id', $actor->id);
    }

    /**
     * The swap is bookkeeping, not a sign-in: neither direction may write authentication-log
     * rows, touch the last-login summary, or mail a new-device alert. Only the audit trail
     * records the borrowed window.
     */
    public function test_the_swap_is_invisible_to_the_authentication_log(): void
    {
        Notification::fake();

        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        // History on the target's own device: without suppression the swap would record the
        // admin's unknown device and trigger the new-device notification.
        $target->authentications()->create([
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (target device)',
            'device_id' => 'target-device',
            'device_name' => 'Target device',
            'login_at' => now()->subDay(),
            'login_successful' => true,
            'last_activity_at' => now()->subDay(),
        ]);

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $this->assertSame(1, $target->authentications()->count());
        $this->assertNull($target->refresh()->last_login_at);
        $this->assertNull($target->last_login_ip);

        $this->deleteJson('/api/impersonation')->assertOk();
        $this->refreshGuards();

        // The restore is a swap too: the admin keeps their single genuine login episode.
        $this->assertSame(1, $actor->authentications()->count());

        Notification::assertNothingSent();
    }

    /**
     * The unrestorable-stop teardown fires a guard-level Logout for the target; its IP + user
     * agent fallback would otherwise close a row belonging to one of the target's own devices.
     */
    public function test_destroying_the_session_leaves_the_targets_own_rows_untouched(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        // An open row the teardown's Logout fallback would match: the test client's IP and agent.
        $log = $target->authentications()->create([
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'device_id' => 'target-device',
            'device_name' => 'Target device',
            'login_at' => now()->subHour(),
            'login_successful' => true,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();
        $actor->delete();

        // The per-request cutoff tears the session down before stop() is reached; same teardown, same invariant.
        $this->deleteJson('/api/impersonation')->assertUnauthorized();

        $this->assertNull($log->refresh()->logout_at);
    }

    /**
     * Only the service writes the marker, but a malformed one must read as "not impersonating"
     * rather than erroring on every request that serializes the user.
     */
    public function test_a_malformed_marker_reads_as_not_impersonating(): void
    {
        $this->actingAsImpersonator();

        $this->withSession(['impersonation' => 'garbage'])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.impersonation', null);

        $this->withSession(['impersonation' => ['started_at' => 'x']])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.impersonation', null);

        // A marker without the actor's credential pin is malformed too.
        $this->withSession(['impersonation' => ['actor_id' => 1, 'started_at' => 'x']])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.impersonation', null);
    }

    public function test_stop_without_active_impersonation_is_refused(): void
    {
        $this->actingAsImpersonator();

        $this->deleteJson('/api/impersonation')
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.name', 'user');
    }

    /**
     * A logout mid-impersonation ends everything, but never without a trace: the audit window is
     * closed, and the teardown spares what belongs to the target - their remember token (backing
     * remember-me cookies on their own devices) and their authentication log.
     */
    public function test_logout_while_impersonating_records_the_end_and_spares_the_target(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser(['remember_token' => 'target-remember-token']);

        // An open row on one of the target's own devices that a guard-level Logout could close.
        $log = $target->authentications()->create([
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'device_id' => 'target-device',
            'device_name' => 'Target device',
            'login_at' => now()->subHour(),
            'login_successful' => true,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $this->postJson('/api/logout')->assertNoContent();
        $this->refreshGuards();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
        $this->assertSame('target-remember-token', $target->refresh()->remember_token);
        $this->assertNull($log->refresh()->logout_at);
    }

    /**
     * A target deactivated mid-impersonation is cut off by EnsureUserCanAuthenticate on the next
     * request; that teardown must close the audit window too, not just kill the session.
     */
    public function test_a_target_cut_off_mid_impersonation_still_gets_the_ended_audit(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $target->forceFill(['is_active' => false])->save();

        $this->getJson('/api/user')->assertForbidden();
        $this->refreshGuards();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_stop_destroys_the_session_when_the_actor_was_retired(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();
        $actor->delete();

        // Reach stop() directly: the per-request cutoff would otherwise answer first.
        $this->withoutMiddleware(EnsureUserCanAuthenticate::class)
            ->deleteJson('/api/impersonation')
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->refreshGuards();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    /**
     * The borrowed session is the admin's browser: it is filed under them in the session registry, so
     * their revocations reach it and the target's session list never shows a device that is not theirs.
     * The swap rotates the session id, and the row follows it rather than opening a second one.
     */
    public function test_the_borrowed_session_is_registered_under_the_actor(): void
    {
        $actor = $this->userWithPermissions('users.impersonate');
        $target = $this->createUser();
        $this->loginAndCarrySession($actor);
        $rowId = $this->registryRowId($this->currentSessionId());

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();

        $this->assertDatabaseHas('user_sessions', [
            'id' => $rowId,
            'session_id' => $this->currentSessionId(),
            'user_id' => $actor->id,
        ]);
        $this->assertSame(1, $this->liveSessionCount($actor));
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $target->id]);
    }

    /**
     * Account recovery on the admin must not spare a session that answers as someone else: the reset
     * destroys the borrowed session outright, and a request on its cookie is cut off with the ended audit.
     * The reset here runs on the borrowed session itself, which saves it back as it finishes: the tombstone
     * on its row is what signs the next request out.
     */
    public function test_password_recovery_on_the_actor_destroys_the_borrowed_session(): void
    {
        Notification::fake();

        $actor = $this->userWithPermissions('users.impersonate');
        $target = $this->createUser();
        $this->loginAndCarrySession($actor);

        $swap = $this->postJson("/api/access/users/{$target->id}/impersonate");
        $swap->assertOk();
        $this->carrySessionCookieFrom($swap);
        $this->refreshGuards();
        $borrowedSessionId = $this->currentSessionId();

        $this->postJson('/api/password/reset', [
            'token' => Password::broker()->createToken($actor),
            'email' => $actor->email,
            'password' => 'Recovered-Passw0rd!',
            'password_confirmation' => 'Recovered-Passw0rd!',
        ])->assertOk();

        $this->assertTrue(
            DB::table('user_sessions')->where('session_id', $borrowedSessionId)->whereNotNull('revoked_at')->exists()
        );
        $this->assertSame(0, $this->liveSessionCount($actor));

        // The next request on the cookie is signed out and the written-back copy destroyed.
        $this->refreshGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($borrowedSessionId));

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    /**
     * A borrowed session signs out through the teardown, whose first step drops the registry row.
     * When that fails, nothing else may have changed: the marker and its restrictions stay, rather than the session
     * being saved as the target's own with no row to revoke it by.
     */
    public function test_a_teardown_that_cannot_drop_the_registry_row_leaves_the_borrowed_session_as_it_was(): void
    {
        $actor = $this->userWithPermissions('users.impersonate');
        $target = $this->createUser();
        $this->loginAndCarrySession($actor);

        $swap = $this->postJson("/api/access/users/{$target->id}/impersonate");
        $swap->assertOk();
        $this->carrySessionCookieFrom($swap);
        $rowId = $this->registryRowId($this->currentSessionId());

        $this->failRegistryWrites('DELETE');
        $this->postJson('/api/logout')->assertStatus(500);
        $this->refreshGuards();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.impersonation.actor_id', $actor->id);
        $this->assertDatabaseHas('user_sessions', ['id' => $rowId, 'user_id' => $actor->id, 'revoked_at' => null]);
        $this->assertDatabaseMissing('access_audit_logs', ['action' => 'user.impersonation_ended']);
    }

    public function test_the_actors_other_sessions_revocation_reaches_the_borrowed_session(): void
    {
        $actor = $this->userWithPermissions('users.impersonate');
        $target = $this->createUser();
        $this->loginAndCarrySession($actor);

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $borrowedSessionId = $this->currentSessionId();

        // The admin, from another browser on a session of their own, signs out everything else.
        $this->dropSessionCookie();
        $this->flushSession();
        $this->refreshGuards();
        $this->loginAndCarrySession($actor);
        $this->refreshGuards();

        $this->deleteJson('/api/sessions/others', ['password' => 'password'])->assertOk();

        $this->assertSessionRevoked($borrowedSessionId);
    }

    /**
     * The marker pins the actor's credentials at swap time; stop() refuses to restore an actor whose
     * password has changed since, even when the session escaped every revocation.
     */
    public function test_stop_destroys_the_session_when_the_actors_password_changed(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $actor->forceFill(['password' => 'Changed-Passw0rd!'])->save();

        // Reach stop() directly: the per-request cutoff would otherwise answer first.
        $this->withoutMiddleware(EnsureUserCanAuthenticate::class)
            ->deleteJson('/api/impersonation')
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->refreshGuards();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    /**
     * An actor retired mid-impersonation cannot keep acting as the target until they choose to stop:
     * the next request tears the borrowed session down, ended audit included.
     */
    public function test_an_actor_deactivated_mid_impersonation_takes_the_session_down(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $actor->forceFill(['is_active' => false])->save();

        $this->getJson('/api/user')->assertUnauthorized();
        $this->refreshGuards();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    /**
     * The target's own password change flushes the borrowed session through Sanctum's password pin,
     * before any controller runs; the audit window must still be closed on the way out.
     */
    public function test_the_targets_password_change_ends_the_impersonation_with_its_audit(): void
    {
        $actor = $this->actingAsImpersonator();
        $target = $this->createUser();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->refreshGuards();

        $target->forceFill(['password' => 'Changed-Passw0rd!'])->save();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.impersonation_ended',
            'actor_id' => $actor->id,
            'subject_id' => $target->id,
        ]);
    }

    /**
     * The id the test client's session ended the last request with.
     * Requests carry no cookie, so this is the only handle on the session that was just written.
     */
    private function currentSessionId(): string
    {
        return $this->app['session']->driver()->getId();
    }
}
