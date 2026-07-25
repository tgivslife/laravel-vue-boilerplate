<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Configure the Horizon authorization services.
     *
     * Overrides the parent to drop its local-environment bypass: the gate is enforced everywhere, so permission behavior
     * can be exercised in local development too.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static function (Request $request): bool {
            return Gate::check('viewHorizon', [$request->user()]);
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in every environment (authorization() above removes the stock local bypass):
     * the settings.manage capability, mirroring the settings routes.
     * Super admins pass every gate through the Gate::before bypass registered in AccessServiceProvider.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (User $user): bool {
            return $user->can('settings.manage');
        });
    }
}
