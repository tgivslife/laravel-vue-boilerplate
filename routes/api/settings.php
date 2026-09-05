<?php

use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\AuthenticationLogController;
use App\Http\Controllers\Settings\IdentityController;
use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Middleware\EnsureNotImpersonating;
use Illuminate\Support\Facades\Route;

/*
 * Self-service settings: the signed-in user acting on their own account.
 * Mutations are closed to impersonated sessions - an admin borrowing an identity must not quietly edit it from here;
 * The audited administrative path is the access panel.
 */
Route::middleware('protected')->group(function () {
    Route::get('tokens', [PersonalAccessTokenController::class, 'index'])->name('tokens.index');
    Route::post('tokens', [PersonalAccessTokenController::class, 'store'])
        ->middleware(['throttle:pat-create', EnsureNotImpersonating::class])
        ->name('tokens.store');
    Route::delete('tokens/{tokenId}', [PersonalAccessTokenController::class, 'destroy'])
        ->middleware(EnsureNotImpersonating::class)
        ->name('tokens.destroy');

    // Name-only, so no takeover risk - but a self-service edit would be attributed to the target;
    // the audited path for an admin is the access panel's account update.
    Route::patch('profile', [ProfileController::class, 'update'])
        ->middleware(EnsureNotImpersonating::class)
        ->name('profile.update');
    Route::patch('preferences', [PreferencesController::class, 'update'])
        ->middleware(EnsureNotImpersonating::class)
        ->name('preferences.update');
    Route::delete('account', [AccountController::class, 'destroy'])
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('account.destroy');

    Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('sessions/others', [SessionController::class, 'destroyOthers'])
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('sessions.destroy-others');
    Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy'])
        ->where('sessionId', '[a-fA-F0-9]{64}')
        ->middleware(EnsureNotImpersonating::class)
        ->name('sessions.destroy');

    Route::get('authentication-log', [AuthenticationLogController::class, 'index'])
        ->name('authentication-log.index');

    Route::get('identities', [IdentityController::class, 'index'])->name('identities.index');
    Route::delete('identities/{provider}', [IdentityController::class, 'destroy'])
        ->whereIn('provider', array_keys((array) config('security.identity_providers.providers', [])))
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('identities.destroy');
});
