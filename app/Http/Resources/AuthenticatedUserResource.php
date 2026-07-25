<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Access\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthenticatedUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $this */
        return [
            'id' => $this->getKey(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'has_password' => $this->getAttribute('password') !== null,
            'two_factor_available' => (bool) config('security.two_factor.enabled', true),
            'identity_providers_available' => (bool) config('security.identity_providers.enabled', true),
            'impersonation_available' => (bool) config('access.impersonation.enabled', false),
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'two_factor_enrollment_required' => $this->mustEnrollTwoFactor(),
            'require_password_reset' => (bool) $this->require_password_reset,
            'preferences' => $this->resolvedPreferences(),
            'roles' => RoleResource::collection($this->roles),
            'permissions' => PermissionResource::collection($this->effectivePermissions()),
            'impersonation' => $this->impersonation($request),
        ];
    }

    /**
     * The borrowed-identity marker driving the SPA's impersonation banner: null on ordinary sessions,
     * the entering admin and start time while impersonation is active.
     * Derived from the session at read time; the actor resolves even after their own deletion
     * so the banner never goes blank mid-session.
     *
     * @return array{actor_id: int, actor_name: string, started_at: string}|null
     */
    private function impersonation(Request $request): ?array
    {
        $state = app(ImpersonationService::class)->state($request);

        if ($state === null) {
            return null;
        }

        $actor = User::withTrashed()->find($state['actor_id']);

        return [
            'actor_id' => $state['actor_id'],
            'actor_name' => $actor === null ? '' : trim($actor->first_name.' '.$actor->last_name),
            'started_at' => $state['started_at'],
        ];
    }

    /**
     * The grants the client may act on. Super admins bypass every check server-side (Gate::before),
     * so their assigned list would understate reality - report the full vocabulary instead,
     * keeping the frontend a dumb mirror with no bypass logic of its own.
     */
    private function effectivePermissions()
    {
        /** @var User $this */
        if ($this->hasRole(config('access.super_admin_role'))) {
            return config('permission.models.permission')::where('guard_name', config('access.guard'))->get();
        }

        return $this->getAllPermissions();
    }
}
