<?php

namespace Tests\Feature\Access;

use App\Models\Access\AccessAuditLog;
use App\Models\Access\RequiredPermission;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\Access\AccessControlService;
use App\Services\Auth\MagicLinkService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Widget;

class AccessAdminApiTest extends AccessTestCase
{
    /**
     * A signed-in admin (role holding every access capability).
     */
    private function actingAsManager(): User
    {
        $role = config('permission.models.role')::findOrCreate('managers', config('access.guard'));
        $role->givePermissionTo(['users.view', 'users.manage', 'roles.view', 'roles.manage']);

        $user = $this->createUser();
        $user->assignRole($role);

        $this->actingAsStateful($user);

        return $user;
    }

    public function test_the_access_api_requires_a_capability(): void
    {
        $user = $this->createUser();
        $role = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $this->actingAsStateful($user);

        $this->getJson('/api/access/users')->assertStatus(403);
        $this->getJson('/api/access/roles')->assertStatus(403);
        $this->getJson("/api/access/roles/{$role->getKey()}")->assertStatus(403);
        $this->putJson("/api/access/users/{$user->id}/roles", ['role_ids' => []])->assertStatus(403);
    }

    public function test_the_access_api_requires_authentication(): void
    {
        $this->getJson('/api/access/users')->assertStatus(401);
    }

    public function test_users_are_listed_with_roles_and_direct_permissions(): void
    {
        $admin = $this->actingAsManager();
        $other = $this->userWithPermissions('widgets.special');

        $response = $this->getJson('/api/access/users')->assertOk();

        $users = collect($response->json('data.users'))->keyBy('id');

        $this->assertSame(['managers'], array_column($users[$admin->id]['roles'], 'name'));
        $this->assertSame(['widgets.special'], array_column($users[$other->id]['direct_permissions'], 'name'));
        $this->assertFalse($response->json('data.has_more'));
        $this->assertSame(2, $response->json('data.total'));
    }

    public function test_user_stats_report_population_and_weekly_intake(): void
    {
        $admin = $this->actingAsManager();

        $this->createUser(['email_verified_at' => null]);
        $this->createUser(['is_active' => false]);
        $this->createUser(['banned_at' => now()]);
        $this->createUser(['created_at' => now()->subDays(10)]);

        app(AccessControlService::class)->deleteUser($admin, $this->createUser());

        $stats = $this->getJson('/api/access/users/stats')->assertOk()->json('data.stats');

        // Tombstoned accounts leave every live count and surface in their own.
        $this->assertSame(5, $stats['total']);
        $this->assertSame(1, $stats['deleted']);
        // Inactive and banned accounts drop out of the active count.
        $this->assertSame(3, $stats['active']);
        $this->assertSame(1, $stats['unverified']);
        // Everyone but the 10-day-old account was created this week.
        $this->assertSame(4, $stats['new_this_week']);
        $this->assertSame(300, $stats['new_this_week_delta']);
    }

    public function test_users_are_exported_as_csv_honoring_filters(): void
    {
        $this->actingAsManager();
        $this->createUser(['first_name' => 'Zeburiah', 'last_name' => 'Quixote', 'email' => 'zeb@example.com']);
        $this->createUser(['first_name' => 'Other', 'email' => 'other@example.com']);

        $response = $this->get('/api/access/users/export?filter[search]=zeburiah')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('status,invited,roles', $csv);
        $this->assertStringContainsString('zeb@example.com', $csv);
        $this->assertStringNotContainsString('other@example.com', $csv);
    }

    public function test_a_user_is_created_with_a_temporary_password_and_roles(): void
    {
        $this->actingAsManager();
        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));

        $response = $this->postJson('/api/access/users', [
            'first_name' => 'New',
            'last_name' => 'Account',
            'email' => 'new.account@example.com',
            'role_ids' => [$editors->getKey()],
        ])->assertStatus(201);

        $temporaryPassword = $response->json('data.temporary_password');
        $this->assertSame(16, strlen($temporaryPassword));
        $this->assertTrue($response->json('data.user.require_password_reset'));
        $this->assertSame(['editors'], array_column($response->json('data.user.roles'), 'name'));

        $created = User::where('email', 'new.account@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($temporaryPassword, $created->password));

        // Duplicate emails fail validation; so does granting super-admin.
        $this->postJson('/api/access/users', [
            'first_name' => 'Dupe', 'last_name' => 'Account', 'email' => 'new.account@example.com',
        ])->assertStatus(422);

        $superAdmin = config('permission.models.role')::findOrCreate(config('access.super_admin_role'),
            config('access.guard'));
        $this->postJson('/api/access/users', [
            'first_name' => 'Rogue', 'last_name' => 'Account', 'email' => 'rogue@example.com',
            'role_ids' => [$superAdmin->getKey()],
        ])->assertStatus(422);
        $this->assertNull(User::where('email', 'rogue@example.com')->first());
    }

    public function test_a_user_is_created_with_an_invitation_instead_of_a_password(): void
    {
        Notification::fake();
        // Pinned rather than inherited from the deployment's .env: this test covers the both-doors-open stamps.
        config(['security.password_login.enabled' => true, 'security.magic_link.enabled' => true]);
        $this->actingAsManager();

        $response = $this->postJson('/api/access/users', [
            'first_name' => 'Invited',
            'last_name' => 'Account',
            'email' => 'invited@example.com',
            'delivery' => 'invitation',
        ])->assertStatus(201);

        // No credential leaves the server; the mailed link is the onboarding.
        $this->assertNull($response->json('data.temporary_password'));
        $this->assertTrue($response->json('data.user.invitation_pending'));
        // Both doors are on in the test default, so no forced password choice.
        $this->assertFalse($response->json('data.user.require_password_reset'));

        $created = User::where('email', 'invited@example.com')->firstOrFail();
        $this->assertNull($created->password);
        Notification::assertSentTo($created, InvitationNotification::class);

        $token = MagicLinkToken::query()->sole();
        $this->assertSame(MagicLinkToken::PURPOSE_INVITATION, $token->purpose);
        $this->assertSame($created->id, $token->user_id);

        $this->assertDatabaseHas('access_audit_logs', ['action' => 'user.invited']);
    }

    public function test_an_invitation_forces_the_password_gate_only_when_password_is_the_sole_door(): void
    {
        Notification::fake();
        config(['security.password_login.enabled' => true, 'security.magic_link.enabled' => false]);
        $this->actingAsManager();

        $this->postJson('/api/access/users', [
            'first_name' => 'Sole', 'last_name' => 'Door', 'email' => 'sole.door@example.com',
            'delivery' => 'invitation',
        ])->assertStatus(201)
            ->assertJsonPath('data.user.require_password_reset', true);
    }

    public function test_the_invitation_two_factor_mandate_follows_config(): void
    {
        Notification::fake();
        config(['security.invitations.two_factor_required' => true]);
        $this->actingAsManager();

        $this->postJson('/api/access/users', [
            'first_name' => 'Mandated', 'last_name' => 'Account', 'email' => 'mandated@example.com',
            'delivery' => 'invitation',
        ])->assertStatus(201)
            ->assertJsonPath('data.user.two_factor_required', true);
    }

    public function test_delivery_choices_follow_the_deployment_doors(): void
    {
        Notification::fake();
        $this->actingAsManager();

        config(['security.invitations.enabled' => false]);
        $this->postJson('/api/access/users', [
            'first_name' => 'No', 'last_name' => 'Invites', 'email' => 'no.invites@example.com',
            'delivery' => 'invitation',
        ])->assertStatus(422);

        config(['security.invitations.enabled' => true, 'security.password_login.enabled' => false]);
        $this->postJson('/api/access/users', [
            'first_name' => 'No', 'last_name' => 'Passwords', 'email' => 'no.passwords@example.com',
            'delivery' => 'temporary_password',
        ])->assertStatus(422);

        // Omitted delivery on a link-only deployment falls back to an invitation:
        // a temporary password nobody can type would be a trap.
        $response = $this->postJson('/api/access/users', [
            'first_name' => 'Link', 'last_name' => 'Only', 'email' => 'link.only@example.com',
        ])->assertStatus(201);

        $this->assertNull($response->json('data.temporary_password'));
        $this->assertNull(User::where('email', 'link.only@example.com')->sole()->password);

        // The fallback honors the switches too: with no delivery mode possible at all,
        // an omitted delivery is a validation error, not a link the consume side refuses.
        config(['security.invitations.enabled' => false]);
        $this->postJson('/api/access/users', [
            'first_name' => 'No', 'last_name' => 'Doors', 'email' => 'no.doors@example.com',
        ])->assertStatus(422);
        $this->assertNull(User::where('email', 'no.doors@example.com')->first());
    }

    public function test_the_search_filter_is_case_insensitive(): void
    {
        $this->actingAsManager();
        $target = $this->createUser(['first_name' => 'Zeburiah', 'email' => 'zeb@example.com']);

        $users = $this->getJson('/api/access/users?filter[search]=ZEBURIAH')->assertOk()->json('data.users');

        $this->assertSame([$target->id], array_column($users, 'id'));
    }

    public function test_the_two_factor_filter_narrows_by_posture(): void
    {
        $admin = $this->actingAsManager();
        $enrolled = $this->createUser(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()]);
        $mandated = $this->createUser(['two_factor_required' => true]);

        $idsFor = fn(string $state): array => collect(
            $this->getJson('/api/access/users?filter[two_factor]='.$state)->assertOk()->json('data.users')
        )->pluck('id')->sort()->values()->all();

        $this->assertSame([$enrolled->id], $idsFor('enabled'));
        $this->assertSame([$mandated->id], $idsFor('required'));
        $this->assertSame([$admin->id], $idsFor('disabled'));

        $this->getJson('/api/access/users?filter[two_factor]=bogus')->assertStatus(422);
    }

    public function test_the_onboarding_filter_narrows_by_pending_state(): void
    {
        Notification::fake();
        $this->actingAsManager();
        $invited = $this->createUser(['password' => null, 'email_verified_at' => null]);
        app(MagicLinkService::class)->invite($invited);
        $resetPending = $this->createUser(['require_password_reset' => true]);
        $unverified = $this->createUser(['email_verified_at' => null]);

        $idsFor = fn(string $state): array => collect(
            $this->getJson('/api/access/users?filter[onboarding]='.$state)->assertOk()->json('data.users')
        )->pluck('id')->sort()->values()->all();

        // `invited` matches the badge derivation, so the merely-unverified account stays out;
        // `unverified` is the broader condition and includes the invited account too.
        $this->assertSame([$invited->id], $idsFor('invited'));
        $this->assertSame([$resetPending->id], $idsFor('reset_pending'));
        $this->assertSame([$invited->id, $unverified->id], $idsFor('unverified'));

        $this->getJson('/api/access/users?filter[onboarding]=bogus')->assertStatus(422);
    }

    public function test_the_users_list_carries_full_account_details(): void
    {
        $admin = $this->actingAsManager();
        $banned = $this->createUser(['banned_at' => now(), 'ban_reason' => 'Abuse']);

        $users = collect($this->getJson('/api/access/users')->assertOk()->json('data.users'))->keyBy('id');

        foreach ([
                     'email_verified', 'is_active', 'banned_at', 'ban_reason', 'two_factor_enabled',
                     'require_password_reset', 'password_changed_at', 'last_login_at', 'last_login_ip', 'created_at',
                 ] as $key) {
            $this->assertArrayHasKey($key, $users[$admin->id]);
        }

        $this->assertFalse($users[$admin->id]['two_factor_enabled']);
        $this->assertNull($users[$admin->id]['banned_at']);
        $this->assertNotNull($users[$admin->id]['created_at']);

        $this->assertNotNull($users[$banned->id]['banned_at']);
        $this->assertSame('Abuse', $users[$banned->id]['ban_reason']);
    }

    public function test_the_users_list_is_searchable(): void
    {
        $this->actingAsManager();
        $this->createUser(['first_name' => 'Zeburiah', 'last_name' => 'Quixote']);

        $response = $this->getJson('/api/access/users?filter[search]=zeburiah')->assertOk();

        $this->assertCount(1, $response->json('data.users'));
        $this->assertSame('Zeburiah', $response->json('data.users.0.first_name'));
    }

    public function test_the_users_list_filters_by_role_and_status(): void
    {
        $admin = $this->actingAsManager();

        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $editor = $this->createUser();
        $editor->assignRole($editors);

        $inactive = $this->createUser(['is_active' => false]);
        $banned = $this->createUser(['banned_at' => now(), 'ban_reason' => 'Abuse']);
        $bannedInactive = $this->createUser(['is_active' => false, 'banned_at' => now()]);

        $byRole = $this->getJson('/api/access/users?filter[role_id]='.$editors->getKey())->assertOk();
        $this->assertSame([$editor->id], array_column($byRole->json('data.users'), 'id'));

        // Banned dominates: a deactivated-and-banned account is "banned", not "inactive".
        $byStatus = $this->getJson('/api/access/users?filter[status]=inactive')->assertOk();
        $this->assertSame([$inactive->id], array_column($byStatus->json('data.users'), 'id'));

        $active = $this->getJson('/api/access/users?filter[status]=active')->assertOk();
        $activeIds = array_column($active->json('data.users'), 'id');
        $this->assertContains($admin->id, $activeIds);
        $this->assertNotContains($inactive->id, $activeIds);
        $this->assertNotContains($banned->id, $activeIds);

        $byBanned = $this->getJson('/api/access/users?filter[status]=banned')->assertOk();
        $this->assertSame([$bannedInactive->id, $banned->id], array_column($byBanned->json('data.users'), 'id'));

        $this->getJson('/api/access/users?filter[role_id]=999999')->assertStatus(422);
        $this->getJson('/api/access/users?filter[status]=nuked')->assertStatus(422);
    }

    public function test_the_deleted_filter_lists_only_tombstoned_accounts(): void
    {
        $admin = $this->actingAsManager();
        $target = $this->createUser(['email' => 'gone@example.com']);

        app(AccessControlService::class)->deleteUser($admin, $target);

        $deleted = $this->getJson('/api/access/users?filter[status]=deleted')->assertOk();
        $this->assertSame([$target->id], array_column($deleted->json('data.users'), 'id'));
        $this->assertNotNull($deleted->json('data.users.0.deleted_at'));

        // The default listing keeps excluding tombstoned rows.
        $all = $this->getJson('/api/access/users')->assertOk();
        $this->assertNotContains($target->id, array_column($all->json('data.users'), 'id'));
    }

    public function test_a_tombstoned_account_is_readable_but_not_mutable(): void
    {
        $admin = $this->actingAsManager();
        $target = $this->createUser();

        app(AccessControlService::class)->deleteUser($admin, $target);

        $response = $this->getJson("/api/access/users/{$target->id}")->assertOk();
        $this->assertNotNull($response->json('data.user.deleted_at'));
        $this->assertStringEndsWith('@deleted.invalid', $response->json('data.user.email'));

        // Reads reach the record (the deletion audit entry must stay readable);
        // mutations keep 404ing - deletion is final.
        $this->getJson("/api/access/users/{$target->id}/audit-logs")->assertOk();
        $this->patchJson("/api/access/users/{$target->id}", ['first_name' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/access/users/{$target->id}")->assertStatus(404);
    }

    public function test_membership_lookup_answers_across_account_lifetimes(): void
    {
        $admin = $this->actingAsManager();
        $live = $this->createUser(['email' => 'here@example.com']);
        $gone = $this->createUser(['email' => 'gone@example.com']);

        app(AccessControlService::class)->deleteUser($admin, $gone);

        $active = $this->getJson('/api/access/users/membership?email=here@example.com')->assertOk();
        $this->assertSame('active', $active->json('data.status'));
        $this->assertSame($live->id, $active->json('data.user.id'));

        // The tombstone hash answers for the original address the row no longer carries.
        $deleted = $this->getJson('/api/access/users/membership?email=gone@example.com')->assertOk();
        $this->assertSame('deleted', $deleted->json('data.status'));
        $this->assertSame($gone->id, $deleted->json('data.user.id'));

        $none = $this->getJson('/api/access/users/membership?email=never@example.com')->assertOk();
        $this->assertSame('none', $none->json('data.status'));
        $this->assertNull($none->json('data.user'));

        $this->getJson('/api/access/users/membership?email=not-an-email')->assertStatus(422);
    }

    public function test_export_includes_tombstoned_rows_when_filtered(): void
    {
        $admin = $this->actingAsManager();
        $target = $this->createUser(['first_name' => 'Ghost', 'last_name' => 'Account']);

        app(AccessControlService::class)->deleteUser($admin, $target);

        $csv = $this->get('/api/access/users/export?filter[status]=deleted')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ghost', $csv);
        $this->assertStringContainsString('deleted', $csv);
        $this->assertStringNotContainsString($admin->email, $csv);
    }

    public function test_the_users_list_page_size_is_selectable(): void
    {
        $this->actingAsManager();

        foreach (range(1, 12) as $i) {
            $this->createUser();
        }

        $response = $this->getJson('/api/access/users?per_page=10')->assertOk();

        $this->assertCount(10, $response->json('data.users'));
        $this->assertTrue($response->json('data.has_more'));

        $this->getJson('/api/access/users?per_page=7')->assertStatus(422);
        $this->getJson('/api/access/users?per_page=all')->assertStatus(422);
        // Values above the largest allowed size are rejected, not clamped.
        $this->getJson('/api/access/users?per_page=10000')->assertStatus(422);
    }

    public function test_unknown_filter_keys_are_rejected(): void
    {
        $this->actingAsManager();

        $this->getJson('/api/access/users?filter[password]=secret')->assertStatus(400);
        $this->getJson('/api/access/protectables/widget/records?filter[id]=1')->assertStatus(400);
    }

    public function test_a_user_detail_includes_the_effective_permission_set(): void
    {
        $this->actingAsManager();

        $role = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $role->givePermissionTo($this->permission('widgets.a'));

        $target = $this->userWithPermissions('widgets.b');
        $target->assignRole($role);

        $response = $this->getJson("/api/access/users/{$target->id}")->assertOk();

        $this->assertSame(
            ['widgets.a', 'widgets.b'],
            $response->json('data.user.effective_permissions')
        );
    }

    public function test_a_user_detail_includes_connected_identities(): void
    {
        $this->actingAsManager();

        $target = $this->createUser();
        $target->identities()->create([
            'provider' => 'roeid',
            'subject' => 'subject-123',
            'last_used_at' => now(),
        ]);
        // Inserted second but sorts first: pins the provider ordering, which the resource
        // applies over the loaded relation rather than in SQL.
        $target->identities()->create([
            'provider' => 'azure',
            'subject' => 'subject-456',
            'last_used_at' => now(),
        ]);

        $response = $this->getJson("/api/access/users/{$target->id}")->assertOk();

        $identities = $response->json('data.user.identities');
        $this->assertCount(2, $identities);
        $this->assertSame(['azure', 'roeid'], array_column($identities, 'provider'));
        $this->assertNotNull($identities[0]['linked_at']);
        $this->assertNotNull($identities[1]['last_used_at']);

        // The list stays lean: identities are detail-only.
        $listed = collect($this->getJson('/api/access/users')->json('data.users'))
            ->firstWhere('id', $target->id);
        $this->assertArrayNotHasKey('identities', $listed);
    }

    public function test_no_list_row_includes_the_detail_only_fields(): void
    {
        // Every row, not just the first: ::collection() builds rows through mapInto(),
        // which passes the collection key as a second constructor argument - a positional
        // detailed flag once made rows 1+ leak the detail-only fields while row 0 hid it.
        $this->actingAsManager();
        $this->createUser();
        $this->createUser();

        $rows = $this->getJson('/api/access/users')->assertOk()->json('data.users');

        $this->assertGreaterThanOrEqual(3, count($rows));
        foreach ($rows as $index => $row) {
            $this->assertArrayNotHasKey('effective_permissions', $row, "row {$index}");
            $this->assertArrayNotHasKey('identities', $row, "row {$index}");
        }
    }

    public function test_user_roles_are_synced_through_the_api(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();
        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));

        $response = $this->putJson("/api/access/users/{$target->id}/roles", [
            'role_ids' => [$editors->getKey()],
        ])->assertOk();

        $this->assertSame(['editors'], array_column($response->json('data.user.roles'), 'name'));
        $this->assertTrue($target->fresh()->hasRole('editors'));
    }

    public function test_self_revocation_through_the_api_returns_422(): void
    {
        $admin = $this->actingAsManager();

        $this->putJson("/api/access/users/{$admin->id}/roles", ['role_ids' => []])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'access');

        $this->assertTrue($admin->fresh()->hasRole('managers'));
    }

    public function test_super_admin_membership_cannot_be_changed_through_the_api(): void
    {
        $this->actingAsManager();
        $superAdmin = config('permission.models.role')::findOrCreate(config('access.super_admin_role'),
            config('access.guard'));
        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));

        // Escalation: granting the bypass role to a regular user is refused.
        $target = $this->createUser();
        $this->putJson("/api/access/users/{$target->id}/roles", ['role_ids' => [$superAdmin->getKey()]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'access');
        $this->assertFalse($target->fresh()->hasRole($superAdmin));

        // Demotion: stripping it from a holder is refused too - a rogue
        // admin must not be able to neutralize the break-glass account.
        $holder = $this->createUser();
        $holder->assignRole($superAdmin);
        $this->putJson("/api/access/users/{$holder->id}/roles", ['role_ids' => []])
            ->assertStatus(422);
        $this->assertTrue($holder->fresh()->hasRole($superAdmin));

        // Membership kept as-is no longer helps a regular manager: the target ceiling
        // refuses any mutation aimed at a super admin, so their other roles are editable
        // only from the super-admin tier.
        $this->putJson("/api/access/users/{$holder->id}/roles", [
            'role_ids' => [$superAdmin->getKey(), $editors->getKey()],
        ])->assertStatus(422);
        $this->assertFalse($holder->fresh()->hasRole('editors'));
    }

    public function test_unknown_role_ids_fail_validation(): void
    {
        $this->actingAsManager();
        $target = $this->createUser();

        $this->putJson("/api/access/users/{$target->id}/roles", ['role_ids' => [999]])
            ->assertStatus(422);
    }

    public function test_roles_are_listed_with_the_protected_flag(): void
    {
        $this->actingAsManager();
        config('permission.models.role')::findOrCreate(config('access.super_admin_role'), config('access.guard'));

        $response = $this->getJson('/api/access/roles')->assertOk();

        $roles = collect($response->json('data.roles'))->keyBy('name');

        $this->assertTrue($roles[config('access.super_admin_role')]['protected']);
        $this->assertFalse($roles['managers']['protected']);
        $this->assertSame(1, $roles['managers']['users_count']);
        $this->assertNotNull($roles['managers']['created_at']);
    }

    public function test_the_roles_list_paginates_and_searches_when_asked(): void
    {
        $this->actingAsManager();

        foreach (range(1, 11) as $i) {
            config('permission.models.role')::findOrCreate(sprintf('crew-%02d', $i), config('access.guard'));
        }

        // Without per_page the full dictionary comes back in one response.
        $dictionary = $this->getJson('/api/access/roles')->assertOk();
        $this->assertCount(12, $dictionary->json('data.roles'));
        $this->assertFalse($dictionary->json('data.has_more'));
        $this->assertSame(12, $dictionary->json('data.total'));

        $paged = $this->getJson('/api/access/roles?per_page=10')->assertOk();
        $this->assertCount(10, $paged->json('data.roles'));
        $this->assertTrue($paged->json('data.has_more'));
        $this->assertSame(12, $paged->json('data.total'));

        // Newest first, mirroring the users browser.
        $searched = $this->getJson('/api/access/roles?per_page=10&filter[search]=crew-1')->assertOk();
        $this->assertSame(['crew-11', 'crew-10'], array_column($searched->json('data.roles'), 'name'));

        $this->getJson('/api/access/roles?per_page=7')->assertStatus(422);
    }

    public function test_the_role_stats_summarize_role_composition(): void
    {
        $this->actingAsManager();

        $roleModel = config('permission.models.role');

        // Held by a user but granting nothing.
        $empty = $roleModel::findOrCreate('empty', config('access.guard'));
        $this->createUser()->assignRole($empty);

        // Granting a permission but held by nobody.
        $unused = $roleModel::findOrCreate('unused', config('access.guard'));
        $unused->givePermissionTo('users.view');

        // The protected role is exempt from the hygiene counts even though
        // it grants no explicit permissions; only active holders count.
        $superAdmin = $roleModel::findOrCreate(config('access.super_admin_role'), config('access.guard'));
        $this->createUser()->assignRole($superAdmin);
        $this->createUser(['banned_at' => now()])->assignRole($superAdmin);
        $this->createUser(['is_active' => false])->assignRole($superAdmin);

        $stats = $this->getJson('/api/access/roles/stats')->assertOk()->json('data.stats');

        // 'managers' (the acting admin's role) + empty + unused + super-admin.
        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['unused']);
        $this->assertSame(1, $stats['empty']);
        $this->assertSame(1, $stats['super_admin_holders']);
    }

    public function test_a_role_is_shown_with_its_permissions_and_holder_count(): void
    {
        $this->actingAsManager();

        $role = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $role->givePermissionTo('users.view');
        $this->createUser()->assignRole($role);

        $response = $this->getJson("/api/access/roles/{$role->getKey()}")->assertOk();

        $this->assertSame('editors', $response->json('data.role.name'));
        $this->assertSame(1, $response->json('data.role.users_count'));
        $this->assertNotNull($response->json('data.role.created_at'));
        $this->assertContains('users.view', array_column($response->json('data.role.permissions'), 'name'));

        $this->getJson('/api/access/roles/999999')->assertStatus(404);
    }

    public function test_role_lifecycle_through_the_api(): void
    {
        $admin = $this->actingAsManager();

        $created = $this->postJson('/api/access/roles', ['name' => 'auditors'])->assertStatus(201);
        $roleId = $created->json('data.role.id');

        $this->patchJson("/api/access/roles/{$roleId}", ['name' => 'inspectors'])
            ->assertOk()
            ->assertJsonPath('data.role.name', 'inspectors');

        $permission = $this->permission('widgets.a');
        $admin->givePermissionTo($permission);
        // The guard caches the signed-in instance across in-test requests; drop it so the
        // grant-ceiling check sees the fresh grant.
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/access/roles/{$roleId}/permissions", ['permission_ids' => [$permission->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.role.permissions.0.name', 'widgets.a');

        // Re-sending the same set is a no-op and records no second audit entry.
        $this->putJson("/api/access/roles/{$roleId}/permissions", ['permission_ids' => [$permission->getKey()]])
            ->assertOk();
        $this->assertSame(1, AccessAuditLog::where('action', 'role.permissions_synced')->count());

        $this->deleteJson("/api/access/roles/{$roleId}")->assertStatus(204);
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_the_super_admin_role_cannot_be_deleted_through_the_api(): void
    {
        $this->actingAsManager();
        $superAdmin = config('permission.models.role')::findOrCreate(config('access.super_admin_role'),
            config('access.guard'));

        $this->deleteJson("/api/access/roles/{$superAdmin->getKey()}")->assertStatus(422);
        $this->assertDatabaseHas('roles', ['id' => $superAdmin->getKey()]);
    }

    public function test_the_permission_vocabulary_is_listed_read_only(): void
    {
        $this->actingAsManager();

        $response = $this->getJson('/api/access/permissions')->assertOk();

        $this->assertContains(
            'users.manage',
            array_column($response->json('data.permissions'), 'name')
        );
        // The vocabulary is code-seeded: no create endpoint exists.
        $this->postJson('/api/access/permissions', ['name' => 'rogue'])->assertStatus(405);
    }

    public function test_the_permissions_list_paginates_and_searches_when_asked(): void
    {
        $this->actingAsManager();

        foreach (range(1, 11) as $i) {
            config('permission.models.permission')::findOrCreate(sprintf('crates.%02d', $i), config('access.guard'));
        }

        // Without per_page the full dictionary comes back in one response.
        $dictionary = $this->getJson('/api/access/permissions')->assertOk();
        $this->assertFalse($dictionary->json('data.has_more'));
        $this->assertSame(count($dictionary->json('data.permissions')), $dictionary->json('data.total'));

        $paged = $this->getJson('/api/access/permissions?per_page=10')->assertOk();
        $this->assertCount(10, $paged->json('data.permissions'));
        $this->assertTrue($paged->json('data.has_more'));
        $this->assertSame($dictionary->json('data.total'), $paged->json('data.total'));

        // Newest first, mirroring the roles browser.
        $searched = $this->getJson('/api/access/permissions?per_page=10&filter[search]=crates.1')->assertOk();
        $this->assertSame(['crates.11', 'crates.10'], array_column($searched->json('data.permissions'), 'name'));

        $this->getJson('/api/access/permissions?per_page=7')->assertStatus(422);
    }

    public function test_the_permission_stats_summarize_vocabulary_coverage(): void
    {
        $this->actingAsManager();

        $editors = config('permission.models.role')::findOrCreate('editors', config('access.guard'));
        $editors->givePermissionTo('users.view');

        // A permission no role grants, held by one user directly.
        $this->userWithPermissions('widgets.special');

        $stats = $this->getJson('/api/access/permissions/stats')->assertOk()->json('data.stats');

        // The seeded vocabulary (users view/manage/impersonate, roles view/manage, settings.manage) plus widgets.special.
        $this->assertSame(7, $stats['total']);
        // No role grants widgets.special (held directly), users.impersonate or settings.manage.
        $this->assertSame(3, $stats['unassigned']);
        $this->assertSame(1, $stats['direct_grants']);
        // 'managers' (the acting admin's role) and 'editors' both grant users.view.
        $this->assertSame('users.view', $stats['most_granted']['name']);
        $this->assertSame(2, $stats['most_granted']['roles_count']);
    }

    public function test_protectables_are_listed_from_the_whitelist(): void
    {
        $this->actingAsManager();

        $response = $this->getJson('/api/access/protectables')->assertOk();

        $this->assertSame('widget', $response->json('data.protectables.0.alias'));
        $this->assertSame(config('access.rule_types'), $response->json('data.protectables.0.rule_types'));

        $this->getJson('/api/access/protectables/session/rules')->assertStatus(404);
    }

    public function test_class_rules_are_managed_through_the_api(): void
    {
        $this->actingAsManager();
        $permission = $this->permission('widgets.classified');

        $this->putJson('/api/access/protectables/widget/rules', [
            'type' => 'view',
            'mode' => 'all',
            'permission_ids' => [$permission->getKey()],
        ])->assertOk()->assertJsonPath('data.rules.0.permissions.0.name', 'widgets.classified');

        $this->getJson('/api/access/protectables/widget/rules')
            ->assertOk()
            ->assertJsonPath('data.rules.0.type', 'view')
            ->assertJsonPath('data.rules.0.mode', 'all');

        $this->assertSame(1, RequiredPermission::classLevel()->count());
    }

    public function test_record_rules_are_managed_through_the_api(): void
    {
        $this->actingAsManager();
        $widget = Widget::create(['name' => 'Gear Box']);
        $permission = $this->permission('widgets.special');

        $this->putJson("/api/access/protectables/widget/records/{$widget->id}", [
            'type' => 'update',
            'mode' => 'any',
            'permission_ids' => [$permission->getKey()],
        ])->assertOk();

        $records = $this->getJson('/api/access/protectables/widget/records?filter[search]=gear')->assertOk();
        $this->assertSame('Gear Box', $records->json('data.records.0.label'));
        $this->assertTrue($records->json('data.records.0.has_rules'));

        $this->getJson("/api/access/protectables/widget/records/{$widget->id}")
            ->assertOk()
            ->assertJsonPath('data.rules.0.type', 'update')
            ->assertJsonPath('data.rules.0.mode', 'any');

        $this->getJson('/api/access/protectables/widget/records/999999')->assertStatus(404);
    }

    public function test_rule_type_outside_the_configured_list_fails_validation(): void
    {
        $this->actingAsManager();

        $this->putJson('/api/access/protectables/widget/rules', [
            'type' => 'transmogrify',
            'mode' => 'all',
            'permission_ids' => [],
        ])->assertStatus(422);
    }

    public function test_a_super_admin_passes_the_gate_without_the_permission(): void
    {
        $superAdmin = $this->createUser();
        $superAdmin->assignRole(
            config('permission.models.role')::findOrCreate(config('access.super_admin_role'), config('access.guard'))
        );

        $this->actingAsStateful($superAdmin);

        $this->getJson('/api/access/users')->assertOk();
    }

    public function test_the_user_endpoint_reports_effective_grants_for_super_admins(): void
    {
        $this->permission('widgets.special');

        $superAdmin = $this->createUser();
        $superAdmin->assignRole(
            config('permission.models.role')::findOrCreate(config('access.super_admin_role'), config('access.guard'))
        );

        $this->actingAsStateful($superAdmin);

        // No assigned permissions, yet the client receives the full
        // vocabulary - the server-side Gate::before bypass, mirrored.
        $names = array_column($this->getJson('/api/user')->assertOk()->json('data.permissions'), 'name');

        $this->assertContains('users.manage', $names);
        $this->assertContains('widgets.special', $names);
    }

    public function test_the_user_endpoint_reports_only_held_grants_for_regular_users(): void
    {
        $this->permission('widgets.other');

        $regular = $this->userWithPermissions('widgets.special');
        $this->actingAsStateful($regular);

        $names = array_column($this->getJson('/api/user')->assertOk()->json('data.permissions'), 'name');

        $this->assertSame(['widgets.special'], $names);
    }
}
