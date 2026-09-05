<?php

use App\Http\Controllers\Access\AppSettingController;
use App\Http\Controllers\Access\ImpersonationController;
use App\Http\Controllers\Access\PermissionController;
use App\Http\Controllers\Access\ProtectableController;
use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserAccessController;
use App\Http\Controllers\Access\UserAccountController;
use App\Http\Middleware\EnsureNotImpersonating;
use Illuminate\Support\Facades\Route;

/**
 * Access administration - session only, personal access tokens cannot mutate access control.
 * Each subgroup is gated by its resource capability ({users,roles}.{view,manage}); super admins pass via Gate::before.
 * Protectable rules rewrite authorization outcomes like role grants do, so they sit under roles.manage.
 * Every mutation runs through AccessControlService (lockout guards + audit trail).
 * Closed to impersonated sessions (EnsureNotImpersonating): the audit trail's actor must never be borrowed.
 */
Route::middleware(['protected', EnsureNotImpersonating::class])
    ->prefix('access')->as('access.')->group(function () {
        Route::middleware('can:users.view')->group(function () {
            Route::get('users', [UserAccessController::class, 'index'])->name('users.index');
            // Literal segments must register before the {user} wildcard.
            Route::get('users/stats', [UserAccessController::class, 'stats'])->name('users.stats');
            Route::get('users/export', [UserAccessController::class, 'export'])->name('users.export');
            Route::get('users/membership', [UserAccessController::class, 'membership'])->name('users.membership');

            // Reads resolve tombstoned accounts too - deletion audit entries must stay readable.
            // Mutations (below) deliberately keep 404ing for them: deletion is final.
            Route::get('users/{user}', [UserAccessController::class, 'show'])
                ->withTrashed()->name('users.show');
            Route::get('users/{user}/sessions', [UserAccountController::class, 'sessions'])
                ->withTrashed()->name('users.sessions');
            Route::get('users/{user}/authentication-logs', [UserAccountController::class, 'authenticationLogs'])
                ->withTrashed()->name('users.authentication-logs');
            Route::get('users/{user}/audit-logs', [UserAccountController::class, 'auditLogs'])
                ->withTrashed()->name('users.audit-logs');
        });

        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [UserAccessController::class, 'store'])->name('users.store');
            Route::put('users/{user}/roles', [UserAccessController::class, 'syncRoles'])
                ->name('users.roles');
            Route::put('users/{user}/permissions', [UserAccessController::class, 'syncPermissions'])
                ->name('users.permissions');

            Route::patch('users/{user}', [UserAccountController::class, 'update'])
                ->name('users.update');
            Route::post('users/{user}/force-password-reset', [UserAccountController::class, 'forcePasswordReset'])
                ->name('users.force-password-reset');
            Route::post('users/{user}/resend-invitation', [UserAccountController::class, 'resendInvitation'])
                ->name('users.resend-invitation');
            Route::delete('users/{user}/two-factor', [UserAccountController::class, 'resetTwoFactor'])
                ->name('users.two-factor-reset');
            Route::delete('users/{user}', [UserAccountController::class, 'destroy'])
                ->name('users.destroy');
        });

        Route::middleware('can:users.impersonate')->group(function () {
            Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])
                ->name('users.impersonate');
        });

        Route::middleware('can:roles.view')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            // The literal segments must register before the {role} wildcard.
            Route::get('roles/stats', [RoleController::class, 'stats'])->name('roles.stats');
            // The role-surface change feed; deleted roles live on here, so it binds no {role}.
            Route::get('roles/audit-logs', [RoleController::class, 'auditLogs'])->name('roles.audit-logs');
            Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
            Route::get('permissions/stats', [PermissionController::class, 'stats'])->name('permissions.stats');
        });

        Route::middleware('can:roles.manage')->group(function () {
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::patch('roles/{role}', [RoleController::class, 'update'])
                ->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])
                ->name('roles.destroy');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])
                ->name('roles.permissions');

            Route::get('protectables', [ProtectableController::class, 'index'])->name('protectables.index');
            Route::get('protectables/{alias}/rules',
                [ProtectableController::class, 'classRules'])->name('protectables.rules');
            Route::put('protectables/{alias}/rules',
                [ProtectableController::class, 'syncClassRules'])->name('protectables.rules.sync');
            Route::get('protectables/{alias}/records',
                [ProtectableController::class, 'records'])->name('protectables.records');
            Route::get('protectables/{alias}/records/{recordId}', [ProtectableController::class, 'recordRules'])
                ->name('protectables.records.rules');
            Route::put('protectables/{alias}/records/{recordId}', [ProtectableController::class, 'syncRecordRules'])
                ->name('protectables.records.rules.sync');
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
