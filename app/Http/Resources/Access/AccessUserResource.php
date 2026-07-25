<?php

namespace App\Http\Resources\Access;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user in the admin users browser: identity, account state, security posture, roles and direct permissions.
 * The detailed form adds the effective permission set (direct + via roles) the editor renders as disabled checks.
 */
final class AccessUserResource extends JsonResource
{
    public function __construct($resource, private readonly bool $detailed = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

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
            'identities' => $this->when(
                $this->detailed,
                static fn(): array => $user->identities()->orderBy('provider')->get()
                    ->map(static fn($identity): array => [
                        'provider' => $identity->provider,
                        'linked_at' => $identity->created_at?->toISOString(),
                        'last_used_at' => $identity->last_used_at?->toISOString(),
                    ])->all()
            ),
        ];
    }
}
