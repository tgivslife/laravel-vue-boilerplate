<?php

namespace App\Providers;

use App\Contracts\AuthServiceContract;
use App\Services\Auth\StatefulAuthService;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * Password login is session-only: non-browser clients authenticate with
     * personal access tokens created from the SPA, so there is no token-issuing login strategy to switch to.
     */
    public function register(): void
    {
        $this->app->bind(AuthServiceContract::class, StatefulAuthService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
