<?php

namespace App\Http\Resources\Access;

use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user in the admin users browser: identity, account state, security posture, roles and direct permissions.
 * Every row carries the requesting admin's reach verdicts - `manageable` (the target ceiling) and `impersonable`
 * (the strict impersonation tier) - so both the list's row actions and the detail page render out-of-reach
 * accounts read-only instead of surfacing the server's 422s.
 * The detailed form adds the effective permission set (direct + via roles) the editor renders as disabled checks.
 */
final class AccessUserResource extends JsonResource
{
    private bool $detailed = false;

    /**
     * Include the detail-only fields (effective permissions, identities).
     *
     * A fluent switch instead of a constructor flag on purpose: ::collection() builds rows through mapInto(),
     * which passes each collection key as a second constructor argument, a positional bool here would silently flip
     * every row after the first to detailed.
     */
    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $access = app(AccessScope::class);

        return [
            'id' => $user->getKey(),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'is_active' => (bool) $user->is_active,
            'banned_at' => $user->banned_at?->toISOString(),
            'ban_reason' => $user->ban_reason,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'two_factor_required' => (bool) $user->two_factor_required,
            'require_password_reset' => (bool) $user->require_password_reset,
            'invitable' => $user->isInvitable(),
            'invitation_pending' => $this->when(
                $this->detailed || array_key_exists('invitation_pending', $user->getAttributes()),
                static fn(): bool => $user->hasPendingInvitation()
            ),
            'password_changed_at' => $user->password_changed_at?->toISOString(),
            'last_login_at' => $user->last_login_at?->toISOString(),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at?->toISOString(),
            'deleted_at' => $user->deleted_at?->toISOString(),
            'roles' => NamedResource::collection($user->roles),
            'direct_permissions' => NamedResource::collection($user->permissions),
            'effective_permissions' => $this->when(
                $this->detailed,
                static fn(): array => $user->getAllPermissions()->pluck('name')->sort()->values()->all()
            ),
            'manageable' => !$access->targetOutranksActor($request->user(), $user),
            'impersonable' => $access->isSuperAdmin($request->user()) || !$access->isTopTier($user),
            'identities' => $this->when(
                $this->detailed,
                static fn(): array => $user->identities->sortBy('provider')->values()
                    ->map(static fn($identity): array => [
                        'provider' => $identity->provider,
                        'linked_at' => $identity->created_at?->toISOString(),
                        'last_used_at' => $identity->last_used_at?->toISOString(),
                    ])->all()
            ),
        ];
    }
}
