<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Query budgets for the access write path.
 *
 * Every access mutation pays for the lockout invariants twice - once to snapshot pre-mutation state under the row
 * lock, once to re-check after the callback - so anything per-permission in there is multiplied by the number of
 * configured lockout permissions and then doubled. It regressed silently once (four queries per permission per
 * snapshot); these ceilings are here so it cannot again.
 *
 * The numbers are deliberately loose - a few queries of headroom - because the point is to catch a return to
 * per-item querying, not to freeze an exact plan.
 */
class AccessQueryBudgetTest extends AccessTestCase
{
    /**
     * @return array{0: int, 1: int} status and query count
     */
    private function measure(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $status = $request()->status();
        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return [$status, $count];
    }

    public function test_a_role_permission_sync_does_not_query_per_lockout_permission(): void
    {
        $actor = $this->userWithPermissions('users.manage', 'roles.manage', 'roles.view');
        $this->actingAsStateful($actor);

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->syncPermissions([$this->permission('users.view'), $this->permission('widgets.a')]);

        // Warm the route and container so the budget measures request work, not first-hit resolution.
        $this->getJson('/api/access/roles');

        [$status, $queries] = $this->measure(fn() => $this->putJson(
            "/api/access/roles/{$ops->getKey()}/permissions",
            ['permission_ids' => [$this->permission('users.view')->getKey()]],
        ));

        $this->assertSame(200, $status);
        $this->assertLessThanOrEqual(24, $queries, "A successful role permission sync took {$queries} queries.");
    }

    public function test_a_refused_role_permission_sync_stays_cheap(): void
    {
        $actor = $this->userWithPermissions('users.manage', 'roles.manage', 'roles.view');
        $this->actingAsStateful($actor);

        $ops = config('permission.models.role')::findOrCreate('ops', config('access.guard'));
        $ops->givePermissionTo('settings.manage');

        $this->createUser()->assignRole($ops);

        $this->getJson('/api/access/roles');

        [$status, $queries] = $this->measure(fn() => $this->putJson(
            "/api/access/roles/{$ops->getKey()}/permissions",
            ['permission_ids' => []],
        ));

        $this->assertSame(422, $status);
        $this->assertLessThanOrEqual(20, $queries, "A refused role permission sync took {$queries} queries.");
    }

    public function test_the_users_stats_headline_is_one_pass_not_one_query_per_metric(): void
    {
        // Six metrics, previously six count() calls over the same visibleTo() slice. The budget is what stops a
        // seventh metric from silently becoming a seventh scan - and, in a deployment with scope dimensions
        // registered, a seventh copy of their subqueries.
        $actor = $this->userWithPermissions('users.view');
        $this->actingAsStateful($actor);

        $this->getJson('/api/access/users/stats');

        [$status, $queries] = $this->measure(fn() => $this->getJson('/api/access/users/stats'));

        $this->assertSame(200, $status);
        $this->assertLessThanOrEqual(4, $queries, "The users stats headline took {$queries} queries.");
    }

    public function test_the_users_stats_headline_stays_flat_as_the_population_grows(): void
    {
        $actor = $this->userWithPermissions('users.view');
        $this->actingAsStateful($actor);

        $this->getJson('/api/access/users/stats');
        [, $small] = $this->measure(fn() => $this->getJson('/api/access/users/stats'));

        User::factory()->count(40)->create();
        User::factory()->count(5)->create(['is_active' => false]);
        User::factory()->count(5)->create()->each->delete();

        [, $large] = $this->measure(fn() => $this->getJson('/api/access/users/stats'));

        $this->assertSame($small, $large, "Stats cost {$small} queries empty and {$large} queries populated.");
    }

    public function test_the_users_stats_headline_reports_the_same_numbers_as_counting_each_metric(): void
    {
        // The consolidation's correctness net: the conditional aggregates must agree with the per-metric counts
        // they replaced, including which side of the soft-delete scope each metric sits on.
        $actor = $this->userWithPermissions('users.view');
        $this->actingAsStateful($actor);

        User::factory()->count(6)->create(['created_at' => now()->subDays(2)]);
        User::factory()->count(4)->create(['created_at' => now()->subDays(10)]);
        User::factory()->count(3)->create(['is_active' => false]);
        User::factory()->count(2)->unverified()->create();
        User::factory()->count(2)->create(['banned_at' => now()]);
        User::factory()->count(5)->create()->each->delete();

        $stats = $this->getJson('/api/access/users/stats')->assertOk()->json('data.stats');

        $this->assertSame(User::query()->count(), $stats['total']);
        $this->assertSame(
            User::query()->where('is_active', true)->whereNull('banned_at')->count(),
            $stats['active'],
        );
        $this->assertSame(User::query()->whereNull('email_verified_at')->count(), $stats['unverified']);
        $this->assertSame(User::onlyTrashed()->count(), $stats['deleted']);
        $this->assertSame(
            User::query()->where('created_at', '>=', now()->subWeek())->count(),
            $stats['new_this_week'],
        );
    }

    /**
     * A full administrator, with the super-admin row seeded so the membership assertion has something to resolve
     * (an install without it short-circuits and hides a query the deployment would pay).
     */
    private function actingAsFullAdmin(): void
    {
        $roleClass = config('permission.models.role');
        $roleClass::findOrCreate(config('access.super_admin_role'), config('access.guard'));

        $admin = $roleClass::findOrCreate('admin', config('access.guard'));
        $admin->syncPermissions(config('permission.models.permission')::all());

        $actor = $this->createUser();
        $actor->assignRole($admin);

        $this->actingAsStateful($actor);
    }

    /**
     * @return list<int> role ids, each granting one permission
     */
    private function grantableRoles(int $count): array
    {
        $roleClass = config('permission.models.role');

        return collect(range(1, $count))
            ->map(function (int $i) use ($roleClass): int {
                $role = $roleClass::findOrCreate("team{$i}", config('access.guard'));
                $role->syncPermissions([$this->permission('users.view')]);

                return (int) $role->getKey();
            })
            ->all();
    }

    public function test_a_user_role_sync_does_not_query_per_granted_role(): void
    {
        // The grant ceiling asks one question about the union of the added roles' permissions; it used to walk
        // them one at a time, so a six-role payload cost five extra lookups than a one-role payload.
        $this->actingAsFullAdmin();
        $roles = $this->grantableRoles(6);

        $counts = [];

        foreach ([1, 6] as $n) {
            $subject = $this->createUser();
            $this->putJson("/api/access/users/{$subject->id}/roles", ['role_ids' => []]);

            [$status, $queries] = $this->measure(fn() => $this->putJson(
                "/api/access/users/{$subject->id}/roles",
                ['role_ids' => array_slice($roles, 0, $n)],
            ));

            $this->assertSame(200, $status);
            $counts[$n] = $queries;
        }

        $this->assertSame(
            $counts[1],
            $counts[6],
            "One role cost {$counts[1]} queries and six cost {$counts[6]} - the ceiling is scaling per role.",
        );
        $this->assertLessThanOrEqual(34, $counts[1], "A user role sync took {$counts[1]} queries.");
    }

    public function test_a_mutation_resolves_the_super_admin_role_once(): void
    {
        // The membership assertion and both holder snapshots all need it; it is memoized on AccessScope for the
        // request, so exactly one lookup should reach the database however many times it is asked for.
        $this->actingAsFullAdmin();
        $subject = $this->createUser();
        $roles = $this->grantableRoles(1);

        $this->putJson("/api/access/users/{$subject->id}/roles", ['role_ids' => []]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->putJson("/api/access/users/{$subject->id}/roles", ['role_ids' => $roles])->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $lookups = collect($log)
            ->filter(static fn(array $entry): bool => str_contains($entry['query'], 'from "roles"')
                && str_contains($entry['query'], '"name" ='))
            ->count();

        $this->assertSame(1, $lookups, "The super-admin role was resolved {$lookups} times in one mutation.");
    }

    public function test_validating_a_large_permission_payload_costs_one_query_not_one_per_id(): void
    {
        // The per-item Rule::exists() this guards against scaled linearly: the wide payload below would have cost
        // one SELECT per id on top of everything else, so the two budgets would differ by the payload size.
        $actor = $this->userWithPermissions('users.manage', 'roles.manage', 'roles.view');
        $this->actingAsStateful($actor);

        $wide = collect(range(1, 12))
            ->map(fn(int $i): int => $this->permission("widgets.bulk{$i}")->getKey())
            ->all();

        $narrow = config('permission.models.role')::findOrCreate('narrow', config('access.guard'));
        $broad = config('permission.models.role')::findOrCreate('broad', config('access.guard'));

        $this->getJson('/api/access/roles');

        [, $withOne] = $this->measure(fn() => $this->putJson(
            "/api/access/roles/{$narrow->getKey()}/permissions",
            ['permission_ids' => [$wide[0]]],
        ));

        [, $withTwelve] = $this->measure(fn() => $this->putJson(
            "/api/access/roles/{$broad->getKey()}/permissions",
            ['permission_ids' => $wide],
        ));

        // Spatie's syncPermissions writes one pivot row set either way, so the gap must stay small and flat -
        // certainly nowhere near the eleven extra ids.
        $this->assertLessThanOrEqual(
            3,
            $withTwelve - $withOne,
            "Twelve ids cost {$withTwelve} queries against {$withOne} for one - validation is scaling per id.",
        );
    }
}
