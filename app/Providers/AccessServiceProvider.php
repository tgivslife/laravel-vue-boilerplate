<?php

namespace App\Providers;

use App\Services\Access\AccessScope;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
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
    }

    /**
     * Merge the protectable aliases into the application's enforced morph
     * map (declared in AppServiceProvider). The map doubles as the
     * protectables whitelist: a model absent from it cannot be stored in
     * any *_type column, so nothing can be protected (or audited) by
     * accident.
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
}
