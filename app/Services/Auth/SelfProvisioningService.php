<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Access\AccessAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Contracts\Role as RoleContract;

/**
 * Creates accounts for the self-provisioning login doors (the OIDC `provision` link policy, the magic-link `provision` switch).
 *
 * One code path for every channel so the guarantees cannot drift: the caller has already proven mailbox ownership,
 * so the email is marked verified at creation; the account gets no password (the door that created it keeps working,
 * and a password can be added later); the `access.self_provision_roles` defaults are assigned
 * (they must be seeded - a listed-but-missing role fails loudly); and the creation is audited with the account itself
 * as actor, like every self-service security event.
 *
 * Channel-specific guardrails (claim gates, email-collision refusal) stay with the caller; this service only creates.
 */
readonly class SelfProvisioningService
{
    public function __construct(
        protected AccessAuditor $auditor,
    ) {
    }

    /**
     * Create the account; fallbacks fill whatever names the channel could not supply.
     * The email is normalized (trimmed, lowercased) so no two case-variant accounts can coexist.
     * `$twoFactorRequired` stamps the enrollment mandate at birth, for channels whose deployments insist
     * self-provisioned accounts enroll a second factor before the app opens up.
     */
    public function provision(
        string $email,
        ?string $firstName,
        ?string $lastName,
        string $channel,
        bool $twoFactorRequired = false
    ): User {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($email, $firstName, $lastName, $channel, $twoFactorRequired): User {
            $user = User::query()->create([
                'email' => $email,
                'first_name' => trim((string) $firstName) ?: Str::before($email, '@'),
                'last_name' => trim((string) $lastName) ?: '-',
            ]);

            // The channel proved the mailbox: a verified OIDC email claim, or a consumed emailed link.
            $user->forceFill([
                'email_verified_at' => now(),
                'is_active' => true,
                'two_factor_required' => $twoFactorRequired,
            ])->saveQuietly();

            $roles = $this->defaultRoles();

            if ($roles !== []) {
                $user->assignRole($roles);
            }

            $this->auditor->record($user, 'user.self_provisioned', $user, null, [
                'channel' => $channel,
                'roles' => array_map(static fn(RoleContract $role): string => $role->name, $roles),
                'two_factor_required' => $twoFactorRequired,
            ]);

            return $user;
        });
    }

    /**
     * The configured default roles, resolved strictly: every role listed in access.self_provision_roles must already be seeded.
     * A missing role is a deployment misconfiguration and fails provisioning loudly - creating it on the fly would grant
     * nothing (an empty role) while looking like it worked.
     *
     * @return list<RoleContract>
     */
    protected function defaultRoles(): array
    {
        $names = config('access.self_provision_roles', []);

        if ($names === []) {
            return [];
        }

        $roles = config('permission.models.role')::query()
            ->whereIn('name', $names)
            ->where('guard_name', config('access.guard'))
            ->get();

        $missing = array_values(array_diff($names, $roles->pluck('name')->all()));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The configured self-provision roles [%s] are not seeded for guard [%s].',
                implode(', ', $missing),
                config('access.guard'),
            ));
        }

        return $roles->all();
    }
}
