<?php

use App\Http\Controllers\Access\AppSettingController;
use App\Http\Controllers\Access\ImpersonationController;
use App\Http\Controllers\Access\PermissionController;
use App\Http\Controllers\Access\ProtectableController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserAccessController;
use App\Http\Controllers\Access\UserAccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\IdentityProviderController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\AuthenticationLogController;
use App\Http\Controllers\Settings\IdentityController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Controllers\Settings\TwoFactorController;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\EnsurePasswordResetNotRequired;
use App\Http\Middleware\EnsureSessionAuthenticated;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserCanAuthenticate;
use App\Http\Middleware\RequireCaptcha;
use Illuminate\Support\Facades\Route;

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
 * The way out of an impersonated session, deliberately outside the forced-reset and two-factor
 * gates that guard the rest of the app: mid-impersonation the authenticated user is the target,
 * who may be trapped by either gate and holds no impersonation permission - the session marker
 * itself is the authorization to leave.
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

Route::middleware([
    'auth:sanctum', EnsureUserCanAuthenticate::class, EnsureSessionAuthenticated::class,
    EnsurePasswordResetNotRequired::class, EnsureTwoFactorEnrolled::class,
])->group(function () {
    Route::get('tokens', [PersonalAccessTokenController::class, 'index'])->name('tokens.index');
    Route::post('tokens', [PersonalAccessTokenController::class, 'store'])
        ->middleware(['throttle:pat-create', EnsureNotImpersonating::class])
        ->name('tokens.store');
    Route::delete('tokens/{tokenId}', [PersonalAccessTokenController::class, 'destroy'])
        ->whereNumber('tokenId')
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

/**
 * Access administration - session only, personal access tokens cannot mutate access control.
 * Each subgroup is gated by its resource capability ({users,roles}.{view,manage}); super admins pass via Gate::before.
 * Protectable rules rewrite authorization outcomes like role grants do, so they sit under roles.manage.
 * Every mutation runs through AccessControlService (lockout guards + audit trail).
 * Closed to impersonated sessions (EnsureNotImpersonating): the audit trail's actor must never be borrowed.
 */
Route::middleware([
    'auth:sanctum', EnsureUserCanAuthenticate::class, EnsureSessionAuthenticated::class,
    EnsurePasswordResetNotRequired::class, EnsureTwoFactorEnrolled::class, EnsureNotImpersonating::class,
])->prefix('access')->as('access.')->group(function () {
    Route::middleware('can:users.view')->group(function () {
        Route::get('users', [UserAccessController::class, 'index'])->name('users.index');
        // Literal segments must register before the {user} wildcard.
        Route::get('users/stats', [UserAccessController::class, 'stats'])->name('users.stats');
        Route::get('users/export', [UserAccessController::class, 'export'])->name('users.export');
        Route::get('users/membership', [UserAccessController::class, 'membership'])->name('users.membership');

        // Reads resolve tombstoned accounts too - deletion audit entries must stay readable.
        // Mutations (below) deliberately keep 404ing for them: deletion is final.
        Route::get('users/{user}', [UserAccessController::class, 'show'])->withTrashed()->name('users.show');
        Route::get('users/{user}/sessions',
            [UserAccountController::class, 'sessions'])->withTrashed()->name('users.sessions');
        Route::get('users/{user}/authentication-logs',
            [UserAccountController::class, 'authenticationLogs'])->withTrashed()->name('users.authentication-logs');
        Route::get('users/{user}/audit-logs',
            [UserAccountController::class, 'auditLogs'])->withTrashed()->name('users.audit-logs');
    });

    Route::middleware('can:users.manage')->group(function () {
        Route::post('users', [UserAccessController::class, 'store'])->name('users.store');
        Route::put('users/{user}/roles', [UserAccessController::class, 'syncRoles'])->name('users.roles');
        Route::put('users/{user}/permissions',
            [UserAccessController::class, 'syncPermissions'])->name('users.permissions');

        Route::patch('users/{user}', [UserAccountController::class, 'update'])->name('users.update');
        Route::post('users/{user}/force-password-reset',
            [UserAccountController::class, 'forcePasswordReset'])->name('users.force-password-reset');
        Route::post('users/{user}/resend-invitation',
            [UserAccountController::class, 'resendInvitation'])->name('users.resend-invitation');
        Route::delete('users/{user}/two-factor',
            [UserAccountController::class, 'resetTwoFactor'])->name('users.two-factor-reset');
        Route::delete('users/{user}', [UserAccountController::class, 'destroy'])->name('users.destroy');
    });

    Route::middleware('can:users.impersonate')->group(function () {
        Route::post('users/{user}/impersonate',
            [ImpersonationController::class, 'store'])->name('users.impersonate');
    });

    Route::middleware('can:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        // The literal segment must register before the {role} wildcard.
        Route::get('roles/stats', [RoleController::class, 'stats'])->name('roles.stats');
        // Numeric ids only: the binding compares against a bigint key, so a non-numeric segment would
        // reach the database and surface as a 500 rather than the 404 an unknown role gives.
        Route::get('roles/{role}', [RoleController::class, 'show'])->whereNumber('role')->name('roles.show');
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::get('permissions/stats', [PermissionController::class, 'stats'])->name('permissions.stats');
    });

    Route::middleware('can:roles.manage')->group(function () {
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('roles/{role}', [RoleController::class, 'update'])
            ->whereNumber('role')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->whereNumber('role')->name('roles.destroy');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])
            ->whereNumber('role')->name('roles.permissions');

        Route::get('protectables', [ProtectableController::class, 'index'])->name('protectables.index');
        Route::get('protectables/{alias}/rules',
            [ProtectableController::class, 'classRules'])->name('protectables.rules');
        Route::put('protectables/{alias}/rules',
            [ProtectableController::class, 'syncClassRules'])->name('protectables.rules.sync');
        Route::get('protectables/{alias}/records',
            [ProtectableController::class, 'records'])->name('protectables.records');
        Route::get('protectables/{alias}/records/{recordId}', [ProtectableController::class, 'recordRules'])
            ->whereNumber('recordId')->name('protectables.records.rules');
        Route::put('protectables/{alias}/records/{recordId}', [ProtectableController::class, 'syncRecordRules'])
            ->whereNumber('recordId')->name('protectables.records.rules.sync');
    });

    Route::middleware('can:settings.manage')->group(function () {
        Route::get('settings', [AppSettingController::class, 'index'])->name('settings.index');
        Route::get('settings/environment',
            [AppSettingController::class, 'environment'])->name('settings.environment');
        Route::get('settings/config',
            [AppSettingController::class, 'configReport'])->name('settings.config');
        Route::put('settings/{key}', [AppSettingController::class, 'update'])
            ->where('key', '[A-Za-z0-9_.-]+')
            ->name('settings.update');
    });
});
