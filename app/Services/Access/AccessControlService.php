<?php

namespace App\Services\Access;

use App\Models\Access\RequiredPermission;
use App\Models\User;
use App\Notifications\TwoFactorDisabledNotification;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\SessionRegistry;
use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single write path for access control.
 *
 * Every mutation runs in one transaction serialized on the lockout permission rows, is audited with scalar before/after
 * snapshots (no-op mutations write no entry), and is verified against the invariants before commit:
 *
 *  - self-revocation: the acting admin cannot strip their own effective grant of a lockout permission they held when the mutation began;
 *  - last man standing: a mutation cannot strip a lockout permission's last active holder (super admins count as holders of everything);
 *  - target ceiling: no mutation may touch an account holding the super-admin role or a privileged permission the actor lacks;
 *  - grant ceiling: permissions and roles being added must sit within what the actor effectively holds.
 *
 * The lockout invariants hold independently for each configured lockout permission.
 */
final readonly class AccessControlService
{
    public function __construct(
        private AccessScope $access,
        private SessionRegistry $sessionRegistry,
        private TwoFactorService $twoFactor,
        private AccessAuditor $auditor,
        private AccountRetirementService $retirement,
        private MagicLinkService $magicLinks,
    ) {
    }

    /**
     * Replace the target user's roles.
     *
     * @param  User  $actor
     * @param  User  $target
     * @param  list<int>  $roleIds
     */
    public function syncUserRoles(User $actor, User $target, array $roleIds): void
    {
        $this->assertSuperAdminMembershipUnchanged($target, $roleIds);

        $this->mutate($actor, $target, function () use ($actor, $target, $roleIds): void {
            $roles = $this->roleModel()::whereIn('id', $roleIds)->get();

            $before = $target->roles()->pluck('name')->sort()->values()->all();
            // The grant ceiling runs in here rather than before mutate() (where the membership assertion sits) because
            // the added-delta needs the before-state, read inside the transaction under the same lock.
            $this->assertMayGrantRoles($actor, $roles->filter(
                static fn(RoleContract $role): bool => !in_array($role->name, $before, true)
            ));
            $target->syncRoles($roles);
            $after = $target->roles()->pluck('name')->sort()->values()->all();

            if ($before !== $after) {
                $this->audit($actor, 'user.roles_synced', $target, ['roles' => $before], ['roles' => $after]);
            }
        });
    }

    /**
     * Replace the target user's direct permissions.
     *
     * @param  User  $actor
     * @param  User  $target
     * @param  list<int>  $permissionIds
     */
    public function syncUserPermissions(User $actor, User $target, array $permissionIds): void
    {
        $this->mutate($actor, $target, function () use ($actor, $target, $permissionIds): void {
            $permissions = $this->permissionModel()::whereIn('id', $permissionIds)->get();

            $before = $target->getDirectPermissions()->pluck('name')->sort()->values()->all();
            $this->assertMayGrantPermissions($actor, array_diff($permissions->pluck('name')->all(), $before));
            $target->syncPermissions($permissions);
            $after = $target->getDirectPermissions()->pluck('name')->sort()->values()->all();

            if ($before !== $after) {
                $this->audit($actor, 'user.permissions_synced', $target, ['permissions' => $before],
                    ['permissions' => $after]);
            }
        });
    }

    /**
     * Update the target's account facts: name, email verification, activation, ban state and the two-factor enrollment mandate.
     * Only keys present in $attributes are touched, so partial updates stay partial in the audit trail too.
     * Deactivating or banning a last active holder is refused by the invariants.
     * The forced-reset flag is deliberately not writable here: forcing goes through forcePasswordReset(),
     * and only the user clears it, by changing their password.
     *
     * @param  array{first_name?: string, last_name?: string, email_verified?: bool, is_active?: bool, banned?: bool, ban_reason?: ?string, two_factor_required?: bool}  $attributes
     */
    public function updateUserAccount(User $actor, User $target, array $attributes): void
    {
        $this->mutate($actor, $target, function () use ($actor, $target, $attributes): void {
            $before = $this->accountSnapshot($target);

            if (array_key_exists('first_name', $attributes)) {
                $target->first_name = $attributes['first_name'];
            }

            if (array_key_exists('last_name', $attributes)) {
                $target->last_name = $attributes['last_name'];
            }

            if (array_key_exists('email_verified', $attributes)) {
                $target->email_verified_at = $attributes['email_verified']
                    ? ($target->email_verified_at ?? now())
                    : null;
            }

            if (array_key_exists('is_active', $attributes)) {
                $target->is_active = $attributes['is_active'];
            }

            if (array_key_exists('banned', $attributes)) {
                if ($attributes['banned']) {
                    $target->banned_at = $target->banned_at ?? now();
                } else {
                    $target->banned_at = null;
                    $target->ban_reason = null;
                }
            }

            if (array_key_exists('ban_reason', $attributes) && $target->banned_at !== null) {
                $target->ban_reason = $attributes['ban_reason'];
            }

            if (array_key_exists('two_factor_required', $attributes)) {
                $target->two_factor_required = $attributes['two_factor_required'];
            }

            $target->save();

            $after = $this->accountSnapshot($target);

            if ($before !== $after) {
                $this->audit($actor, 'user.account_updated', $target, $before, $after);
            }
        });
    }

    /**
     * Create an account with the chosen onboarding delivery.
     *
     * `temporary_password` is the forcePasswordReset() credential flow starting from nothing: a
     * server-generated password returned exactly once, flagged for a forced reset.
     * `invitation` leaves the account passwordless and mails a single-use first-sign-in link instead;
     * When password login is the only enabled door the account is additionally flagged so the consumed link lands on
     * the choose-your-password gate, and `security.invitations.two_factor_required` stamps the enrollment mandate at birth.
     * The invitation is mailed after the transaction commits, so the queued notification can never reference an uncommitted account.
     *
     * Granting the super-admin role at creation is refused like everywhere else in the API.
     *
     * @param  array{first_name: string, last_name: string, email: string}  $attributes
     * @param  list<int>  $roleIds
     * @return array{user: User, temporary_password: ?string}
     */
    public function createUser(
        User $actor,
        array $attributes,
        array $roleIds = [],
        string $delivery = 'temporary_password'
    ): array {
        $invitation = $delivery === 'invitation';
        $password = $invitation ? null : $this->generateTemporaryPassword();

        $user = $this->mutate($actor, null,
            function () use ($actor, $attributes, $roleIds, $password, $invitation): User {
                $user = new User;
                $user->first_name = $attributes['first_name'];
                $user->last_name = $attributes['last_name'];
                $user->email = $attributes['email'];
                $user->is_active = true;

                if ($invitation) {
                    $user->require_password_reset = (bool) config('security.password_login.enabled', true)
                        && !(bool) config('security.magic_link.enabled', true);
                    $user->two_factor_required = (bool) config('security.invitations.two_factor_required', false);
                } else {
                    $user->password = $password;
                    $user->password_changed_at = now();
                    $user->require_password_reset = true;
                }

                $user->save();

                if ($roleIds !== []) {
                    $this->assertSuperAdminMembershipUnchanged($user, $roleIds);
                    $roles = $this->roleModel()::whereIn('id', $roleIds)->get();
                    $this->assertMayGrantRoles($actor, $roles);
                    $user->syncRoles($roles);
                }

                $this->audit($actor, $invitation ? 'user.invited' : 'user.created', $user, null,
                    $this->accountSnapshot($user) + [
                        'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
                    ]);

                return $user;
            });

        if ($invitation) {
            $this->magicLinks->invite($user);
        }

        return ['user' => $user, 'temporary_password' => $password];
    }

    /**
     * Re-mail a pending invitation, revoking the previous link (MagicLinkService::invite()).
     *
     * Only an account still inside its invited-onboarding window qualifies (User::isInvitable()): a sign-in or a
     * verified email means the original invitation did its job, and re-inviting would hand a live sign-in link
     * to a mailbox whose claim on the account is no longer the only one.
     * An account holding a password was onboarded through the temporary-password flow - a link would sign its user
     * in straight into the password gate, which demands the current password they were never told.
     * Deactivated and banned accounts are refused too - their link would only ever produce "account deactivated".
     */
    public function resendInvitation(User $actor, User $target): void
    {
        if (!$target->isInvitable()) {
            throw ValidationException::withMessages([
                'user' => __('api.access.invitation_not_pending'),
            ]);
        }

        $this->mutate($actor, $target, function () use ($actor, $target): void {
            $this->audit($actor, 'user.invitation_resent', $target, null, ['email' => $target->email]);
        });

        $this->magicLinks->invite($target);
    }

    /**
     * Replace the target's password with a server-generated temporary one, flag the account for a
     * forced reset, and return the plaintext exactly once. Admins never choose the password, so a weak
     * or reused credential cannot enter this flow; the user signs in with it and is blocked
     * (EnsurePasswordResetNotRequired) until they choose their own. Every session is destroyed and the
     * remember token rotated so the old credential stops working everywhere at once. The password
     * itself is never audited.
     */
    public function forcePasswordReset(User $actor, User $target): string
    {
        $password = $this->generateTemporaryPassword();

        $this->mutate($actor, $target, function () use ($actor, $target, $password): void {
            $before = ['require_password_reset' => (bool) $target->require_password_reset];

            $target->password = $password;
            $target->password_changed_at = now();
            $target->require_password_reset = true;
            $target->setRememberToken(Str::random(60));
            $target->save();

            // An explicit switch to temporary-password onboarding: any outstanding
            // invitation link dies with it, like the sessions do.
            $target->invitationTokens()->delete();

            $this->sessionRegistry->destroyAll($target);

            $this->audit($actor, 'user.password_reset_forced', $target, $before, ['require_password_reset' => true]);
        });

        return $password;
    }

    /**
     * Clear the target's two-factor enrollment - secret, recovery codes, any pending setup - for a lost authenticator.
     * The account keeps signing in with its remaining factors; if the enrollment mandate is on, the gate forces a fresh enrollment.
     * The owner is mailed after commit - an unexpected reset is what a takeover looks like.
     * Nothing enrolled or pending is a no-op: no audit entry, no mail.
     */
    public function resetTwoFactor(User $actor, User $target): void
    {
        $wasEnrolled = $this->mutate($actor, $target, function () use ($actor, $target): bool {
            if ($target->two_factor_secret === null) {
                return false;
            }

            $before = ['two_factor_enabled' => $target->hasTwoFactorEnabled()];

            $this->twoFactor->disable($target);

            $this->audit($actor, 'user.two_factor_reset', $target, $before, ['two_factor_enabled' => false]);

            return $before['two_factor_enabled'];
        });

        if ($wasEnrolled && (bool) config('security.two_factor.change_notification.enabled', true)) {
            $target->notify(
                new TwoFactorDisabledNotification(
                    byAdministrator: true,
                    deviceName: null,
                    ipAddress: null,
                    changedAt: now(),
                )->locale(app()->getLocale())
            );
        }
    }

    /**
     * 16 characters from an unambiguous charset (no 0/O, 1/l/I), so a password read aloud over the phone survives transcription.
     */
    private function generateTemporaryPassword(): string
    {
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*';

        return implode('', array_map(
            static fn(): string => $charset[random_int(0, strlen($charset) - 1)],
            range(1, 16),
        ));
    }

    /**
     * Retire the target account (AccountRetirementService: credentials severed, email tombstoned, row soft-deleted)
     * under the lockout invariants, with the audit before-snapshot retaining the original address.
     * Self-deletion is refused outright - the settings page owns that flow; deleting a last active holder is refused by the invariants.
     */
    public function deleteUser(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'access' => __('api.access.self_delete'),
            ]);
        }

        $this->mutate($actor, $target, function () use ($actor, $target): void {
            $before = $this->accountSnapshot($target) + [
                    'roles' => $target->roles()->pluck('name')->sort()->values()->all(),
                ];

            $this->retirement->retire($target);

            $this->audit($actor, 'user.deleted', $target, $before, null);
        });
    }

    /**
     * Scalar snapshot of the mutable account facts, for audit entries.
     *
     * @return array{first_name: ?string, last_name: ?string, email: string, email_verified: bool, is_active: bool, banned: bool, ban_reason: ?string, two_factor_required: bool, require_password_reset: bool}
     */
    private function accountSnapshot(User $user): array
    {
        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'is_active' => (bool) $user->is_active,
            'banned' => $user->banned_at !== null,
            'ban_reason' => $user->ban_reason,
            'two_factor_required' => (bool) $user->two_factor_required,
            'require_password_reset' => (bool) $user->require_password_reset,
        ];
    }

    /**
     * Create a role in the configured guard. The super-admin name is reserved.
     */
    public function createRole(User $actor, string $name): RoleContract
    {
        $this->assertNotSuperAdminName($name);

        return $this->mutate($actor, null, function () use ($actor, $name): RoleContract {
            $role = $this->roleModel()::create([
                'name' => $name,
                'guard_name' => config('access.guard'),
            ]);

            $this->audit($actor, 'role.created', $role, null, ['name' => $name]);

            return $role;
        });
    }

    /**
     * Rename a role. Never the super-admin role: code references it by name, so renaming it would revoke every super admin at once.
     * Nor *into* the super-admin name: holders of the renamed role would become super admins with no membership change.
     */
    public function renameRole(User $actor, RoleContract $role, string $name): RoleContract
    {
        $this->assertNotSuperAdminRole($role);
        $this->assertNotSuperAdminName($name);

        return $this->mutate($actor, null, function () use ($actor, $role, $name): RoleContract {
            $before = ['name' => $role->name];
            $role->update(['name' => $name]);

            $this->audit($actor, 'role.renamed', $role, $before, ['name' => $name]);

            return $role;
        });
    }

    /**
     * Delete a role (never the super-admin role).
     * The invariants still apply: deleting the only path to a lockout permission's last active holder is refused.
     */
    public function deleteRole(User $actor, RoleContract $role): void
    {
        $this->assertNotSuperAdminRole($role);

        $this->mutate($actor, null, function () use ($actor, $role): void {
            $before = [
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
                'users' => $role->users()->count(),
            ];

            $role->delete();

            $this->audit($actor, 'role.deleted', $role, $before, null);
        });
    }

    /**
     * Replace a role's permissions.
     *
     * @param  list<int>  $permissionIds
     */
    public function syncRolePermissions(User $actor, RoleContract $role, array $permissionIds): void
    {
        $this->mutate($actor, null, function () use ($actor, $role, $permissionIds): void {
            $permissions = $this->permissionModel()::whereIn('id', $permissionIds)->get();

            $before = $role->permissions()->pluck('name')->sort()->values()->all();
            $this->assertMayGrantPermissions($actor, array_diff($permissions->pluck('name')->all(), $before));
            $role->syncPermissions($permissions);
            $after = $role->permissions()->pluck('name')->sort()->values()->all();

            if ($before !== $after) {
                $this->audit($actor, 'role.permissions_synced', $role, ['permissions' => $before],
                    ['permissions' => $after]);
            }
        });
    }

    /**
     * Replace the class-level rule group for a protectable alias and type.
     *
     * @param  list<int>  $permissionIds
     */
    public function syncClassRules(User $actor, string $alias, string $type, array $permissionIds, string $mode): void
    {
        $this->assertProtectable($alias);

        $this->mutate($actor, null, function () use ($actor, $alias, $type, $permissionIds, $mode): void {
            $before = $this->ruleSnapshot($alias, null, $type);
            $this->replaceRuleGroup($alias, null, $type, $permissionIds, $mode);

            $this->audit($actor, 'rules.class_synced', null, $before, [
                'protectable' => $alias,
                'type' => $type,
                'mode' => $mode,
                'permission_ids' => array_values($permissionIds),
            ]);
        });
    }

    /**
     * Replace the rule group for one specific record.
     *
     * @param  list<int>  $permissionIds
     */
    public function syncRecordRules(User $actor, Model $record, string $type, array $permissionIds, string $mode): void
    {
        $alias = $record->getMorphClass();
        $this->assertProtectable($alias);

        $this->mutate($actor, null, function () use ($actor, $record, $alias, $type, $permissionIds, $mode): void {
            $before = $this->ruleSnapshot($alias, (int) $record->getKey(), $type);
            $this->replaceRuleGroup($alias, (int) $record->getKey(), $type, $permissionIds, $mode);

            $this->audit($actor, 'rules.record_synced', $record, $before, [
                'protectable' => $alias,
                'type' => $type,
                'mode' => $mode,
                'permission_ids' => array_values($permissionIds),
            ]);
        });
    }

    /**
     * The user ids that effectively hold the permission right now - directly, via a role, or via super admin - read
     * fresh from the pivot tables so mid-transaction state is visible.
     *
     * @return list<int>
     */
    public function effectiveHolderIds(PermissionContract $permission): array
    {
        $tables = config('permission.table_names');
        $userAlias = (new User)->getMorphClass();

        $roleIds = DB::table($tables['role_has_permissions'])
            ->where('permission_id', $permission->getKey())
            ->pluck('role_id');

        $superAdmin = $this->roleModel()::where('name', config('access.super_admin_role'))
            ->where('guard_name', config('access.guard'))
            ->first();

        if ($superAdmin) {
            $roleIds->push($superAdmin->getKey());
        }

        $viaRoles = DB::table($tables['model_has_roles'])
            ->whereIn('role_id', $roleIds)
            ->where('model_type', $userAlias)
            ->pluck(config('permission.column_names.model_morph_key'));

        $direct = DB::table($tables['model_has_permissions'])
            ->where('permission_id', $permission->getKey())
            ->where('model_type', $userAlias)
            ->pluck(config('permission.column_names.model_morph_key'));

        return $viaRoles->merge($direct)->unique()->map(static fn($id): int => (int) $id)->values()->all();
    }

    /**
     * Run a mutation under the shared lock and enforce the invariants before commit; throwing rolls the whole mutation back.
     * The target is part of the signature so no mutation can silently skip the tier decision: user-directed methods
     * pass their target, role/rule methods pass null.
     * The tier is checked inside the transaction, after the lock, so it reads state no concurrent mutation is changing.
     * Pre-mutation state is snapshotted so the lockout guards only fire for what the mutation itself broke: self-revocation
     * for grants the actor actually held, last-holder for permissions that still had an active holder.
     * Permission caches are flushed after commit.
     */
    private function mutate(User $actor, ?User $target, Closure $callback): mixed
    {
        $result = DB::transaction(function () use ($actor, $target, $callback) {
            $permissions = $this->lockLockoutPermissionRows();

            if ($target !== null) {
                $this->assertTargetWithinTier($actor, $target);
            }

            $heldBefore = [];
            $activeBefore = [];

            foreach ($permissions as $permission) {
                $holderIds = $this->effectiveHolderIds($permission);

                if (in_array((int) $actor->getKey(), $holderIds, true)) {
                    $heldBefore[] = (int) $permission->getKey();
                }

                if ($this->countActiveHolders($holderIds) > 0) {
                    $activeBefore[] = (int) $permission->getKey();
                }
            }

            $result = $callback();

            $this->assertStillManageable($actor, $permissions, $heldBefore, $activeBefore);

            return $result;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->access->flush();

        return $result;
    }

    /**
     * Serialize concurrent access mutations on the lockout permission rows so the invariant check cannot race (two admins removing each other).
     * Ordered by id so concurrent transactions acquire the row locks in the same deterministic order.
     *
     * @return Collection<int, PermissionContract>
     */
    private function lockLockoutPermissionRows(): Collection
    {
        return $this->permissionModel()::whereIn('name', config('access.lockout_permissions', []))
            ->where('guard_name', config('access.guard'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, PermissionContract>  $permissions
     * @param  list<int>  $heldBefore  ids of the lockout permissions the actor held pre-mutation
     * @param  list<int>  $activeBefore  ids of the lockout permissions that had an active holder pre-mutation
     */
    private function assertStillManageable(
        User $actor,
        Collection $permissions,
        array $heldBefore,
        array $activeBefore
    ): void {
        foreach ($permissions as $permission) {
            $key = (int) $permission->getKey();
            $holderIds = $this->effectiveHolderIds($permission);

            if (in_array($key, $heldBefore, true) && !in_array((int) $actor->getKey(), $holderIds, true)) {
                throw ValidationException::withMessages([
                    'access' => __('api.access.self_revocation'),
                ]);
            }

            if (in_array($key, $activeBefore, true) && $this->countActiveHolders($holderIds) === 0) {
                throw ValidationException::withMessages([
                    'access' => __('api.access.last_manager'),
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $holderIds
     */
    private function countActiveHolders(array $holderIds): int
    {
        return User::query()
            ->whereIn('id', $holderIds)
            ->where('is_active', true)
            ->whereNull('banned_at')
            ->count();
    }

    /**
     * The super-admin role bypasses every gate, policy and visibility scope, so an access manager must not be able to
     * grant it (privilege escalation) or strip it (neutralizing break-glass); membership changes only via seeder or console.
     * Payloads that keep membership as-is pass this check - but only a super-admin actor gets that far, since the
     * target ceiling refuses everyone else acting on a super admin.
     * Their other roles stay editable from the top tier alone.
     *
     * @param  list<int>  $roleIds
     */
    private function assertSuperAdminMembershipUnchanged(User $target, array $roleIds): void
    {
        $superAdmin = $this->roleModel()::where('name', config('access.super_admin_role'))
            ->where('guard_name', config('access.guard'))
            ->first();

        if ($superAdmin === null) {
            return;
        }

        $requested = in_array((int) $superAdmin->getKey(), array_map('intval', $roleIds), true);
        $current = $target->hasRole($superAdmin);

        if ($requested !== $current) {
            throw ValidationException::withMessages([
                'access' => __('api.access.super_admin_assignment'),
            ]);
        }
    }

    /**
     * The grant ceiling: an actor may only hand out permissions they effectively hold (super admins hold everything).
     * Applies to the names being added relative to current state - removals stay governed by the lockout guards -
     * so a grant already above the actor's ceiling remains removable, just never re-growable.
     *
     * @param  iterable<string>  $addedNames
     */
    private function assertMayGrantPermissions(User $actor, iterable $addedNames): void
    {
        foreach ($addedNames as $name) {
            if (!$this->access->holdsPermission($actor, $name)) {
                throw ValidationException::withMessages([
                    'access' => __('api.access.grant_above_ceiling'),
                ]);
            }
        }
    }

    /**
     * The grant ceiling over roles: assigning a role grants everything it carries, so every
     * permission of every role being added must sit within the actor's ceiling.
     *
     * @param  iterable<RoleContract>  $addedRoles
     */
    private function assertMayGrantRoles(User $actor, iterable $addedRoles): void
    {
        foreach ($addedRoles as $role) {
            $this->assertMayGrantPermissions($actor, $role->permissions()->pluck('name')->all());
        }
    }

    /**
     * The target ceiling: an account holding the super-admin role or an effective privileged permission the actor
     * lacks is out of the actor's administrative reach entirely - no grant edits, no account-fact edits, no
     * credential or two-factor resets. Subset semantics on purpose: equal-tier admins keep managing each other.
     * Self-targeting always passes (nobody outranks themselves); the grant ceiling is what stops self-escalation.
     */
    private function assertTargetWithinTier(User $actor, User $target): void
    {
        if ($this->access->targetOutranksActor($actor, $target)) {
            throw ValidationException::withMessages([
                'access' => __('api.access.target_above_tier'),
            ]);
        }
    }

    private function assertNotSuperAdminRole(RoleContract $role): void
    {
        if ($role->name === config('access.super_admin_role')) {
            throw ValidationException::withMessages([
                'access' => __('api.access.protected_role'),
            ]);
        }
    }

    /**
     * The configured super-admin name is reserved: hasRole() matches by name, so creating or renaming a role into it
     * would mint the break-glass bypass for everyone holding it.
     * The form requests' unique rule only collides in installs that seeded the row - this holds regardless.
     */
    private function assertNotSuperAdminName(string $name): void
    {
        if ($name === config('access.super_admin_role')) {
            throw ValidationException::withMessages([
                'access' => __('api.access.reserved_role_name'),
            ]);
        }
    }

    private function assertProtectable(string $alias): void
    {
        if (!array_key_exists($alias, config('access.protectables', []))) {
            throw ValidationException::withMessages([
                'access' => __('api.access.unknown_protectable'),
            ]);
        }
    }

    /**
     * Replace a rule group in place: drop rows leaving the group, upsert the rest with the group's mode.
     * Serialized by the lockout-permission lock; the partial unique indexes backstop duplicates regardless.
     *
     * @param  list<int>  $permissionIds
     */
    private function replaceRuleGroup(
        string $alias,
        ?int $recordId,
        string $type,
        array $permissionIds,
        string $mode
    ): void {
        RequiredPermission::query()
            ->where('protectable_type', $alias)
            ->when(
                $recordId === null,
                static fn($query) => $query->whereNull('protectable_id'),
                static fn($query) => $query->where('protectable_id', $recordId),
            )
            ->where('type', $type)
            ->whereNotIn('permission_id', $permissionIds)
            ->delete();

        foreach ($permissionIds as $permissionId) {
            RequiredPermission::updateOrCreate([
                'permission_id' => $permissionId,
                'protectable_type' => $alias,
                'protectable_id' => $recordId,
                'type' => $type,
            ], [
                'mode' => $mode,
            ]);
        }

        $this->access->flushRules();
    }

    /**
     * @return array{protectable: string, type: string, mode: ?string, permission_ids: list<int>}
     */
    private function ruleSnapshot(string $alias, ?int $recordId, string $type): array
    {
        $rules = RequiredPermission::query()
            ->where('protectable_type', $alias)
            ->when(
                $recordId === null,
                static fn($query) => $query->whereNull('protectable_id'),
                static fn($query) => $query->where('protectable_id', $recordId),
            )
            ->where('type', $type)
            ->get(['permission_id', 'mode']);

        return [
            'protectable' => $alias,
            'type' => $type,
            'mode' => $rules->first()?->mode,
            'permission_ids' => $rules->pluck('permission_id')->sort()->values()->all(),
        ];
    }

    private function audit(User $actor, string $action, ?Model $subject, ?array $before, ?array $after): void
    {
        $this->auditor->record($actor, $action, $subject, $before, $after);
    }

    /**
     * @return class-string<RoleContract>
     */
    private function roleModel(): string
    {
        return config('permission.models.role');
    }

    /**
     * @return class-string<PermissionContract>
     */
    private function permissionModel(): string
    {
        return config('permission.models.permission');
    }
}
