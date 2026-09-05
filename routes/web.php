<?php

use App\Http\Controllers\Auth\IdentityProviderController;
use Illuminate\Support\Facades\Route;

/*
 * OIDC identity-provider flow: full-page redirects that need the session, so they live in the web group rather than the JSON API.
 * Declared before the SPA catch-all so they win the match.
 */
Route::get('auth/{provider}/redirect', [IdentityProviderController::class, 'redirect'])
    ->whereIn('provider', array_keys((array) config('security.identity_providers.providers', [])))
    ->middleware('throttle:oidc')
    ->name('oidc.redirect');
Route::get('auth/{provider}/callback', [IdentityProviderController::class, 'callback'])
    ->whereIn('provider', array_keys((array) config('security.identity_providers.providers', [])))
    ->middleware('throttle:oidc')
    ->name('oidc.callback');

// Everything but the API and the readiness probe (bootstrap/app.php, registered after this file) is the SPA's.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|up$).*$');
