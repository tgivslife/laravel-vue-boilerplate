<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Access\AccessScope;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * AccessScope is scoped (not singleton) on purpose,
     * its memos must live exactly one request/job so revocations apply on the next one.
     */
    public function register(): void
    {
        $this->app->scoped(AccessScope::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->enforceMorphMap();
        $this->registerSuperAdminBypass();
        $this->registerPolicies();
        $this->bindRolesWithinGuard();
    }

    /**
     * Resolve {role} within the configured guard.
     *
     * The browsers already filter on guard_name, but implicit binding resolves by key alone - so the moment a deployment
     * adds a second guard, its roles would be reachable through the detail, rename, delete and permission-sync endpoints
     * while staying invisible in the list they were reached from.
     * One binding rather than a filter repeated in each controller method, which is how that asymmetry arose.
     *
     * A miss throws ModelNotFoundException, which the framework renders as the same 404 an unknown id gives.
     */
    private function bindRolesWithinGuard(): void
    {
        Route::bind('role', static fn(string $value): Model => config('permission.models.role')::query()
            ->where('guard_name', config('access.guard'))
            ->findOrFail($value));
    }

    /**
     * Merge the protectable aliases into the application's enforced morph map (declared in AppServiceProvider).
     * The map doubles as the protectables whitelist: a model absent from it cannot be stored in any *_type column,
     * so nothing can be protected (or audited) by accident.
     */
    private function enforceMorphMap(): void
    {
        $protectables = collect(config('access.protectables', []))
            ->map(static fn(array $protectable): string => $protectable['model']);

        Relation::enforceMorphMap($protectables->all());
    }

    /**
     * Super admins pass every gate and policy.
     * Returning null (not false) for everyone else lets normal checks proceed.
     */
    private function registerSuperAdminBypass(): void
    {
        Gate::before(static function (Authorizable $user, string $ability): ?bool {
            return method_exists($user, 'hasRole') && $user->hasRole(config('access.super_admin_role'))
                ? true
                : null;
        });
    }

    /**
     * Record-level policies, registered explicitly so the wiring is grep-able (no reliance on policy auto-discovery).
     */
    private function registerPolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
    }
}
