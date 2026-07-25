<?php

use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IdentityProviderServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\SecurityServiceProvider;

/*
 * Dev-only providers (Telescope, Clockwork) are deliberately absent: their packages live in require-dev,
 * so they are registered conditionally in AppServiceProvider::register() instead.
 * An unconditional entry here would crash a --no-dev production build at boot.
 */
return [
    AccessServiceProvider::class,
    AppServiceProvider::class,
    AuthServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityProviderServiceProvider::class,
    RateLimitServiceProvider::class,
    SecurityServiceProvider::class,
];
