<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Arr;
use Tests\Support\UserAllowlistDimension;

/**
 * The record scope on the admin user surface: with a User-claiming dimension registered, every read narrows to the
 * actor's slice and every out-of-scope record answers exactly like one that does not exist (docs/record-scoping.md).
 * Stock deployments (no dimensions, no rules) are covered by the rest of the suite passing unchanged - the scope must be invisible until a deployment opts in.
 */
class ScopedAdminSurfaceTest extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The allowlist is static state and leaks between tests.
        UserAllowlistDimension::$visible = [];
    }

    /**
     * A signed-in manager (users.view + users.manage) whose reach is bounded by the allowlist
     * dimension; they start seeing only themselves.
     */
    private function actingAsScopedManager(): User
    {
        config(['access.dimensions' => [UserAllowlistDimension::class]]);

        $role = config('permission.models.role')::findOrCreate('managers', config('access.guard'));
        $role->givePermissionTo(['users.view', 'users.manage']);

        $actor = $this->createUser();
        $actor->assignRole($role);

        $this->actingAsStateful($actor);

        UserAllowlistDimension::$visible[$actor->id] = [$actor->id];

        return $actor;
    }

    /**
     * Put the targets inside the actor's slice.
     */
    private function allow(User $actor, User ...$targets): void
    {
        foreach ($targets as $target) {
            UserAllowlistDimension::$visible[$actor->id][] = $target->id;
        }
    }

    public function test_the_index_and_export_list_only_the_actor_slice(): void
    {
        $actor = $this->actingAsScopedManager();
        $inScope = $this->createUser(['email' => 'inside@example.com']);
        $this->createUser(['email' => 'outside@example.com']);
        $this->allow($actor, $inScope);

        $response = $this->getJson('/api/access/users')->assertOk();

        $ids = array_column($response->json('data.users'), 'id');
        sort($ids);
        $this->assertSame([$actor->id, $inScope->id], $ids);
        $this->assertSame(2, $response->json('data.total'));

        // The export flows through the same builder - the two surfaces cannot diverge.
        $csv = $this->get('/api/access/users/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('inside@example.com', $csv);
        $this->assertStringNotContainsString('outside@example.com', $csv);
    }

    public function test_stats_count_only_the_actor_slice(): void
    {
        $actor = $this->actingAsScopedManager();
        $inScope = $this->createUser();
        $inScopeTrashed = $this->createUser();
        $this->createUser();
        $outTrashed = $this->createUser();
        $this->allow($actor, $inScope, $inScopeTrashed);

        $inScopeTrashed->delete();
        $outTrashed->delete();

        $stats = $this->getJson('/api/access/users/stats')->assertOk()->json('data.stats');

        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(0, $stats['unverified']);
        // The trashed counter combines the scope with onlyTrashed(): the out-of-scope tombstone stays uncounted.
        $this->assertSame(1, $stats['deleted']);
        $this->assertSame(2, $stats['new_this_week']);
    }

    public function test_membership_answers_none_for_out_of_scope_accounts(): void
    {
        $actor = $this->actingAsScopedManager();
        $inScope = $this->createUser(['email' => 'inside@example.com']);
        $inScopeRetired = $this->createUser(['email' => 'kept@example.com']);
        $this->createUser(['email' => 'outside@example.com']);
        $outRetired = $this->createUser(['email' => 'retired@example.com']);
        $this->allow($actor, $inScope, $inScopeRetired);

        app(AccessControlService::class)->deleteUser($actor, $inScopeRetired);
        app(AccessControlService::class)->deleteUser($actor, $outRetired);

        $this->getJson('/api/access/users/membership?email=inside@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user.id', $inScope->id);

        // In scope, a retirement stays answerable (the tombstone-hash lookup).
        $this->getJson('/api/access/users/membership?email=kept@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.user.id', $inScopeRetired->id);

        // A live account outside the slice answers exactly like an unknown address.
        $this->getJson('/api/access/users/membership?email=outside@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.user', null);

        // The tombstone branch is scoped too - a retired out-of-scope account stays unconfirmed.
        $this->getJson('/api/access/users/membership?email=retired@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.user', null);
    }

    public function test_membership_answers_the_newest_reachable_tombstone(): void
    {
        $actor = $this->actingAsScopedManager();

        $this->travelTo(now()->subDay());
        $older = $this->createUser(['email' => 'twice@example.com']);
        $this->allow($actor, $older);
        app(AccessControlService::class)->deleteUser($actor, $older);
        $this->travelBack();

        // The retirement freed the address, so it lived a second life - retired again, out of scope.
        $newer = $this->createUser(['email' => 'twice@example.com']);
        app(AccessControlService::class)->deleteUser($actor, $newer);

        // The newest tombstone is unreachable; the answer is the newest *reachable* one, not 'none'.
        $this->getJson('/api/access/users/membership?email=twice@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'deleted')
            ->assertJsonPath('data.user.id', $older->id);
    }

    public function test_out_of_scope_records_answer_the_unknown_id_404_on_every_record_route(): void
    {
        $actor = $this->actingAsScopedManager();
        $stranger = $this->createUser();

        // The read pair: apart from the per-request instance id, the bodies must be identical -
        // this is also the tripwire for the renderer path (denyAsNotFound arrives as a plain
        // HttpException and must not fall through to the internal-server-error copy).
        $unknown = $this->getJson('/api/access/users/999999');
        $unknown->assertStatus(404)
            ->assertJsonPath('title', __('api.errors.titles.not_found'))
            ->assertJsonPath('detail', __('api.errors.http.not_found'));

        $outOfScope = $this->getJson("/api/access/users/{$stranger->id}");
        $outOfScope->assertStatus(404);

        $this->assertSame(
            Arr::except($unknown->json(), 'instance'),
            Arr::except($outOfScope->json(), 'instance')
        );

        // The mutation pair goes through the FormRequest authorization path instead of the controller's.
        $unknownPatch = $this->patchJson('/api/access/users/999999', ['first_name' => 'X']);
        $unknownPatch->assertStatus(404);

        $outOfScopePatch = $this->patchJson("/api/access/users/{$stranger->id}", ['first_name' => 'X']);
        $outOfScopePatch->assertStatus(404);

        $this->assertSame(
            Arr::except($unknownPatch->json(), 'instance'),
            Arr::except($outOfScopePatch->json(), 'instance')
        );

        // Every remaining record route answers the same 404.
        $this->getJson("/api/access/users/{$stranger->id}/sessions")->assertStatus(404);
        $this->getJson("/api/access/users/{$stranger->id}/authentication-logs")->assertStatus(404);
        $this->getJson("/api/access/users/{$stranger->id}/audit-logs")->assertStatus(404);
        $this->putJson("/api/access/users/{$stranger->id}/roles", ['role_ids' => []])->assertStatus(404);
        $this->putJson("/api/access/users/{$stranger->id}/permissions", ['permission_ids' => []])->assertStatus(404);
        $this->postJson("/api/access/users/{$stranger->id}/force-password-reset")->assertStatus(404);
        $this->postJson("/api/access/users/{$stranger->id}/resend-invitation")->assertStatus(404);
        $this->deleteJson("/api/access/users/{$stranger->id}/two-factor")->assertStatus(404);
        $this->deleteJson("/api/access/users/{$stranger->id}")->assertStatus(404);

        // Nothing was mutated through the denied doors.
        $this->assertNull($stranger->fresh()->deleted_at);
        $this->assertFalse((bool) $stranger->fresh()->require_password_reset);
    }

    public function test_an_invalid_payload_does_not_leak_an_out_of_scope_account_through_a_422(): void
    {
        $actor = $this->actingAsScopedManager();
        $inScope = $this->createUser();
        $stranger = $this->createUser();
        $this->allow($actor, $inScope);

        // In scope, the invalid payload is the caller's problem.
        $this->putJson("/api/access/users/{$inScope->id}/roles", ['role_ids' => 'nonsense'])->assertStatus(422);

        // Out of scope, authorization answers before validation: a 422 here would distinguish
        // an existing-but-out-of-reach id from a missing one.
        $this->putJson("/api/access/users/{$stranger->id}/roles", ['role_ids' => 'nonsense'])->assertStatus(404);
        $this->putJson("/api/access/users/{$stranger->id}/permissions",
            ['permission_ids' => 'nonsense'])->assertStatus(404);
        $this->patchJson("/api/access/users/{$stranger->id}", ['require_password_reset' => true])->assertStatus(404);
        $this->getJson("/api/access/users/{$stranger->id}/authentication-logs?date=not-a-date")->assertStatus(404);

        // And an unknown id answers the same way for the same payloads.
        $this->putJson('/api/access/users/999999/roles', ['role_ids' => 'nonsense'])->assertStatus(404);
    }

    public function test_trashed_records_follow_the_scope_on_the_tombstone_read_routes(): void
    {
        $actor = $this->actingAsScopedManager();
        $inScopeGone = $this->createUser();
        $strangerGone = $this->createUser();
        $this->allow($actor, $inScopeGone);

        $inScopeGone->delete();
        $strangerGone->delete();

        // In scope: deletion audit entries stay readable (the withTrashed() binding, now behind the scope).
        $this->getJson("/api/access/users/{$inScopeGone->id}")->assertOk();
        $this->getJson("/api/access/users/{$inScopeGone->id}/audit-logs")->assertOk();

        // Out of scope: the tombstone is as invisible as a missing row.
        $this->getJson("/api/access/users/{$strangerGone->id}")->assertStatus(404);
        $this->getJson("/api/access/users/{$strangerGone->id}/audit-logs")->assertStatus(404);
    }

    public function test_the_audit_trail_withholds_the_identity_of_an_out_of_scope_actor(): void
    {
        // The trail's subject is scoped by the policy, but its actor is a second account - and the interesting case is
        // precisely the one the scope exists for: an admin from another slice acting on a record both can see.
        // The entry must still say an actor exists, without naming them.
        $actor = $this->actingAsScopedManager();

        $subject = $this->createUser();
        $this->allow($actor, $subject);

        $peer = $this->createUser(['email' => 'other-slice@example.com', 'first_name' => 'Otto']);
        $peer->givePermissionTo('users.manage');

        // Two mutations of the same in-scope account: one by the viewer, one by an admin they cannot reach.
        app(AccessControlService::class)->updateUserAccount($actor, $subject, ['first_name' => 'Mine']);
        app(AccessControlService::class)->updateUserAccount($peer, $subject, ['first_name' => 'Theirs']);

        $entries = collect($this->getJson("/api/access/users/{$subject->id}/audit-logs")->assertOk()
            ->json('data.entries'));

        $this->assertCount(2, $entries);

        [$withheld, $own] = [
            $entries->firstWhere('actor.restricted', true), $entries->firstWhere('actor.id', $actor->id)
        ];

        // Reachable actor: named as before.
        $this->assertNotNull($own);
        $this->assertSame($actor->email, $own['actor']['email']);
        $this->assertFalse($own['actor']['restricted']);

        // Out-of-scope actor: the marker and nothing else - not the email, name or even the id.
        $this->assertNotNull($withheld);
        $this->assertSame(['restricted' => true], $withheld['actor']);

        // Belt and braces: the identity is absent from the whole payload, not merely from the actor object.
        $body = $this->getJson("/api/access/users/{$subject->id}/audit-logs")->assertOk()->content();
        $this->assertStringNotContainsString('other-slice@example.com', $body);
        $this->assertStringNotContainsString('Otto', $body);

        // The action itself stays on the record - accountability survives the redaction.
        $this->assertSame(['user.account_updated', 'user.account_updated'], $entries->pluck('action')->all());
    }

    public function test_a_super_admin_sees_every_audit_actor(): void
    {
        config(['access.dimensions' => [UserAllowlistDimension::class]]);

        $role = config('permission.models.role')::findOrCreate(
            config('access.super_admin_role'), config('access.guard')
        );
        $admin = $this->createUser();
        $admin->assignRole($role);

        $subject = $this->createUser();
        $peer = $this->userWithPermissions('users.manage');

        app(AccessControlService::class)->updateUserAccount($peer, $subject, ['first_name' => 'Changed']);

        $this->actingAsStateful($admin);

        $this->getJson("/api/access/users/{$subject->id}/audit-logs")
            ->assertOk()
            ->assertJsonPath('data.entries.0.actor.id', $peer->id)
            ->assertJsonPath('data.entries.0.actor.restricted', false);
    }

    public function test_a_super_admin_keeps_full_reach_with_dimensions_active(): void
    {
        config(['access.dimensions' => [UserAllowlistDimension::class]]);

        $role = config('permission.models.role')::findOrCreate(
            config('access.super_admin_role'), config('access.guard')
        );
        $admin = $this->createUser();
        $admin->assignRole($role);
        $this->actingAsStateful($admin);

        // No allowlist entries at all: the dimension would hide everyone from a regular manager.
        $stranger = $this->createUser(['email' => 'anyone@example.com']);

        $index = $this->getJson('/api/access/users')->assertOk();
        $this->assertContains($stranger->id, array_column($index->json('data.users'), 'id'));

        $this->getJson("/api/access/users/{$stranger->id}")->assertOk();
        $this->patchJson("/api/access/users/{$stranger->id}", ['first_name' => 'Seen'])->assertOk();

        $this->getJson('/api/access/users/stats')
            ->assertOk()
            ->assertJsonPath('data.stats.total', 2);

        $this->getJson('/api/access/users/membership?email=anyone@example.com')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }
}
