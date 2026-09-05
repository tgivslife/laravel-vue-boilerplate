<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDO;

/**
 * The lockout invariants under a driver that returns integer columns as strings.
 *
 * holderSnapshot() reads the pivot tables raw and casts the ids itself, and the rest of the suite runs on a driver
 * that already hands back native ints - so nothing exercised that cast. It was wrong: `->map(intval(...))` binds the
 * collection key to intval()'s $base parameter, which is ignored for int input and destructive for strings, so every
 * id after the first collapsed to 0. Silently: the self-revocation and last-man-standing guards would then evaluate
 * against a holder set that mostly read as user 0.
 *
 * PDO::ATTR_STRINGIFY_FETCHES reproduces exactly that shape on the suite's own connection.
 */
class StringifiedIdSnapshotTest extends AccessTestCase
{
    /**
     * RefreshDatabase reuses one PDO handle for the whole process, so the attribute outlives this test class and
     * would stringify every later test's fetches. Reset unconditionally rather than only where it was set.
     */
    protected function tearDown(): void
    {
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

        parent::tearDown();
    }

    private function stringifyFetches(): void
    {
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    }

    public function test_the_driver_really_does_return_strings_under_this_setting(): void
    {
        // Guards the guard: if a future PDO stops honouring the attribute, the tests below would pass vacuously.
        $this->createUser();
        $this->stringifyFetches();

        $ids = DB::table('users')->pluck('id')->all();

        $this->assertNotEmpty($ids);
        $this->assertIsString($ids[0], 'STRINGIFY_FETCHES no longer stringifies - the tests below prove nothing.');
    }

    public function test_effective_holders_survive_stringified_ids(): void
    {
        $permission = $this->permission('users.manage');

        // Several holders, so a collapsed cast loses all but the first: two via a role, two directly.
        $role = config('permission.models.role')::findOrCreate('managers', config('access.guard'));
        $role->givePermissionTo($permission);

        $viaRole = [$this->createUser(), $this->createUser()];
        foreach ($viaRole as $holder) {
            $holder->assignRole($role);
        }

        $direct = [$this->createUser(), $this->createUser()];
        foreach ($direct as $holder) {
            $holder->givePermissionTo($permission);
        }

        $expected = collect([...$viaRole, ...$direct])->map(static fn(User $u): int => $u->id)->sort()->values()->all();

        $this->stringifyFetches();

        $holders = app(AccessControlService::class)->effectiveHolderIds($permission);
        sort($holders);

        $this->assertSame($expected, $holders);
        $this->assertNotContains(0, $holders);
    }

    public function test_self_revocation_still_fires_with_stringified_ids(): void
    {
        /*
         * The invariant that depends on recognising the actor inside the holder set.
         *
         * The actor is created LAST on purpose, so their id sorts to the end of the pivot reads. Only index 0
         * survived the broken cast, so a first-created actor would still have been found and this would have passed
         * against the bug; from the tail, the collapse drops them out of $heldBefore and the guard goes quiet.
         */
        $others = collect(range(1, 4))->map(fn(): User => $this->userWithPermissions('users.manage', 'roles.manage'));

        $actor = $this->userWithPermissions('users.manage', 'roles.manage');

        $this->assertGreaterThan($others->first()->id, $actor->id);

        $this->stringifyFetches();

        $this->expectException(ValidationException::class);

        try {
            app(AccessControlService::class)->syncUserPermissions($actor, $actor, []);
        } catch (ValidationException $e) {
            $this->assertSame(__('api.access.self_revocation'), $e->errors()['access'][0]);

            throw $e;
        }
    }

    public function test_last_man_standing_still_fires_with_stringified_ids(): void
    {
        $actor = $this->userWithPermissions('users.manage', 'roles.manage');
        $sole = $this->userWithPermissions('settings.manage');

        // settings.manage is not a lockout permission, so the sole-holder guard must answer on the lockout ones:
        // deactivating the only other holder of users.manage/roles.manage is what it protects.
        $onlyOther = $this->userWithPermissions('users.manage');

        $this->stringifyFetches();

        // The actor keeps their own grants, so this is the last-holder case rather than self-revocation.
        app(AccessControlService::class)->updateUserAccount($actor, $onlyOther, ['is_active' => false]);

        $this->assertFalse((bool) $onlyOther->fresh()->is_active,
            'The actor still holds users.manage, so this is allowed.');
        $this->assertTrue((bool) $sole->fresh()->is_active);
    }

    public function test_a_mutation_completes_normally_with_stringified_ids(): void
    {
        $actor = $this->userWithPermissions('users.manage', 'roles.manage', 'users.view');
        $target = $this->createUser();

        $this->stringifyFetches();

        app(AccessControlService::class)->syncUserPermissions($actor, $target, [
            $this->permission('users.view')->getKey(),
        ]);

        $this->assertTrue($target->fresh()->hasDirectPermission('users.view'));
    }
}
