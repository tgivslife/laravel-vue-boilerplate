<?php

use App\Http\Controllers\Access\AppSettingController;
use App\Http\Controllers\Access\ImpersonationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\IdentityProviderController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\EnsurePasswordResetNotRequired;
use App\Http\Middleware\EnsureSessionAuthenticated;
use App\Http\Middleware\EnsureUserCanAuthenticate;
use App\Http\Middleware\RequireCaptcha;
use Illuminate\Support\Facades\Route;

/*
 * The authentication surface: the public ways in, and the escape hatches out of the gates that guard everything else.
 * Each escape group carries a hand-picked subset of the `protected` stack (bootstrap/app.php) - the omission is the point,
 * so none of them use the named group.
 */

Route::post('login', [AuthController::class, 'login'])
    ->middleware(['throttle:login', RequireCaptcha::class.':login'])
    ->name('login');
Route::post('two-factor/challenge', [TwoFactorChallengeController::class, 'challenge'])
    ->middleware('throttle:login')
    ->name('two-factor.challenge');
Route::get('auth/methods', [IdentityProviderController::class, 'methods'])->name('auth.methods');
Route::get('settings', [AppSettingController::class, 'publicIndex'])->name('settings.public');

Route::post('magic-link', [MagicLinkController::class, 'send'])
    ->middleware(['throttle:magic-link-request', RequireCaptcha::class.':magic_link'])
    ->name('magic-link.send');
Route::post('magic-link/consume', [MagicLinkController::class, 'consume'])
    ->middleware('throttle:magic-link-consume')
    ->name('magic-link.consume');

Route::post('password/forgot', [PasswordResetController::class, 'send'])
    ->middleware(['throttle:password-reset-request', RequireCaptcha::class.':password_reset'])
    ->name('password.forgot');
Route::post('password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password-reset-attempt')
    ->name('password.reset');

Route::middleware(['auth:sanctum', EnsureUserCanAuthenticate::class])->group(function () {
    Route::get('user', [AuthController::class, 'user'])->name('user');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});

/**
 * The way out of a pending forced password reset,
 * deliberately outside the EnsurePasswordResetNotRequired gate that guards everything else.
 */
Route::middleware([
    'auth:sanctum', EnsureUserCanAuthenticate::class, EnsureSessionAuthenticated::class
])->group(function () {
    Route::put('password', [PasswordController::class, 'update'])
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('password.update');
});

/**
 * The way out of an impersonated session, deliberately outside the forced-reset and two-factor gates that guard the
 * rest of the app: mid-impersonation the authenticated user is the target, who may be trapped by either gate and holds
 * no impersonation permission - the session marker itself is the authorization to leave.
 */
Route::middleware([
    'auth:sanctum', EnsureUserCanAuthenticate::class, EnsureSessionAuthenticated::class,
])->group(function () {
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])
        ->name('impersonation.stop');
});

/**
 * The way out of an administrative two-factor enrollment mandate, deliberately outside the EnsureTwoFactorEnrolled gate
 * that guards the rest of the app.
 * Disable and recovery codes ride along: they are harmless while trapped (nothing is enrolled yet) and this keeps the
 * two-factor surface in one place.
 */
Route::middleware([
    'auth:sanctum', EnsureUserCanAuthenticate::class, EnsureSessionAuthenticated::class,
    EnsurePasswordResetNotRequired::class,
])->group(function () {
    Route::post('two-factor', [TwoFactorController::class, 'store'])
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('two-factor.enroll');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware(EnsureNotImpersonating::class)
        ->name('two-factor.confirm');
    Route::delete('two-factor', [TwoFactorController::class, 'destroy'])
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('two-factor.destroy');
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
        ->middleware(['throttle:password-confirm', EnsureNotImpersonating::class])
        ->name('two-factor.recovery-codes');
});
