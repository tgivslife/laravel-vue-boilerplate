<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
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
        $this->actingAsImpersonator();
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

        $this->deleteJson('/api/impersonation')->assertOk();

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

        $this->deleteJson('/api/impersonation')
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
}
