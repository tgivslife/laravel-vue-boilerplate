<?php

namespace Database\Seeders;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Services\Access\AccountRetirementService;
use App\Services\Auth\MagicLinkTokenHasher;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manual-testing fixture: one account per rung of the tier ladder, plus one per account state the app gates on.
 *
 * Deliberately NOT called from DatabaseSeeder - this is throwaway data for a development database, run explicitly:
 *
 *   php artisan db:seed --class=SampleDataSeeder
 *
 * Idempotent, so a reseed refreshes the fixture instead of colliding on the unique email index.
 * Requires PermissionSeeder and RoleSeeder to have run (the vocabulary, and the super-admin/admin roles).
 */
class SampleDataSeeder extends Seeder
{
    private const string PASSWORD = 'admin1234';

    /**
     * Runtime-composed roles, on top of the seeded super-admin and admin.
     * Chosen to straddle the ceilings: user-manager and role-manager hold lockout permissions, settings-manager holds
     * a privileged one that is deliberately not a lockout permission, and auditor holds nothing privileged at all.
     *
     * @var array<string, list<string>>
     */
    private const array ROLES = [
        'user-manager' => ['users.view', 'users.manage'],
        'role-manager' => ['roles.view', 'roles.manage'],
        'settings-manager' => ['settings.manage'],
        'impersonator' => ['users.view', 'users.impersonate'],
        'auditor' => ['users.view', 'roles.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedRoles();
        $this->seedTierLadder();
        $this->seedAccountStates();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->newLine();
        $this->command?->info('Sample data seeded. Every account signs in with: '.self::PASSWORD);
    }

    /**
     * The demo roles, synced from the seeded vocabulary so a reseed re-flattens hand-edits made through the UI.
     */
    private function seedRoles(): void
    {
        $guard = config('access.guard');
        $roleModel = config('permission.models.role');

        foreach (self::ROLES as $name => $permissions) {
            $roleModel::findOrCreate($name, $guard)->syncPermissions($permissions);
        }

        $this->command?->info('Roles: '.implode(', ', array_keys(self::ROLES)));
    }

    /**
     * One account per rung, so the ceilings can be exercised against each other: usermanager cannot reach settings
     * (a privileged grant it lacks), rolemanager cannot demote settings through the settings-manager role,
     * and everyone is reachable from super.
     */
    private function seedTierLadder(): void
    {
        $ladder = [
            ['super@mail.com', 'Super', 'Admin', config('access.super_admin_role')],
            ['owner@mail.com', 'Owner', 'Admin', 'admin'],
            ['usermanager@mail.com', 'Ursula', 'Manager', 'user-manager'],
            ['rolemanager@mail.com', 'Rob', 'Manager', 'role-manager'],
            ['settings@mail.com', 'Sam', 'Settings', 'settings-manager'],
            ['impersonator@mail.com', 'Iris', 'Borrower', 'impersonator'],
            ['auditor@mail.com', 'Ada', 'Auditor', 'auditor'],
            ['member@mail.com', 'Mila', 'Member', null],
        ];

        foreach ($ladder as [$email, $first, $last, $role]) {
            $this->account($email, $first, $last)
                ->syncRoles($role === null ? [] : [$role]);
        }

        // The direct-grant path: the same capabilities as usermanager, held without a role.
        $direct = $this->account('direct@mail.com', 'Dana', 'Direct');
        $direct->syncRoles([]);
        $direct->syncPermissions(['users.view', 'users.manage']);

        $this->command?->info('Tier ladder: 9 accounts (super > owner > managers > auditor > member).');
    }

    /**
     * One account per state the middleware and the admin browser distinguish, all ungranted so the state is the
     * only variable.
     */
    private function seedAccountStates(): void
    {
        $inactive = $this->account('inactive@mail.com', 'Ivan', 'Inactive');
        $inactive->is_active = false;
        $inactive->save();

        $banned = $this->account('banned@mail.com', 'Bea', 'Banned');
        $banned->banned_at = now();
        $banned->ban_reason = 'Seeded for testing the banned state.';
        $banned->save();

        $unverified = $this->account('unverified@mail.com', 'Uma', 'Unverified');
        $unverified->email_verified_at = null;
        $unverified->save();

        $resetter = $this->account('pwreset@mail.com', 'Pia', 'Resetter');
        $resetter->require_password_reset = true;
        $resetter->save();

        $mandated = $this->account('mfa@mail.com', 'Mo', 'Mandated');
        $mandated->two_factor_required = true;
        $mandated->save();

        $this->seedInvitedAccount();
        $this->seedRetiredAccount();

        $this->command?->info('States: inactive, banned, unverified, pwreset, mfa, invited, retired.');
    }

    /**
     * An account still inside its invited-onboarding window: passwordless, unverified, never signed in, carrying a
     * live invitation link.
     *
     * Minted directly rather than through MagicLinkService::invite() so seeding never sends mail; the plaintext is
     * printed instead, which is what a manual test needs anyway.
     */
    private function seedInvitedAccount(): void
    {
        $invited = $this->account('invited@mail.com', 'Ivy', 'Invited', password: null);
        $invited->email_verified_at = null;
        $invited->last_login_at = null;
        $invited->save();

        $invited->magicLinkTokens()->delete();

        $plaintext = 'seeded-invitation-token';

        MagicLinkToken::query()->create([
            'user_id' => $invited->getKey(),
            'purpose' => MagicLinkToken::PURPOSE_INVITATION,
            'token_hash' => app(MagicLinkTokenHasher::class)->hash($plaintext),
            'expires_at' => now()->addDays((int) config('security.invitations.ttl_days', 7)),
        ]);

        $this->command?->comment(
            '  invitation link: '.url('/auth/magic/verify').'?token='.$plaintext.'&invite=1'
        );
    }

    /**
     * A retired account, through the real retirement path, so the tombstone, the severed credentials and the
     * membership lookup all behave as they would in production.
     * Its address leaves the unique index, so the reseed guard is the tombstone hash rather than the email.
     */
    private function seedRetiredAccount(): void
    {
        $email = 'retired@mail.com';

        if (User::onlyTrashed()->whereDeletedEmail($email)->exists()) {
            return;
        }

        app(AccountRetirementService::class)->retire(
            $this->account($email, 'Rex', 'Retired')
        );
    }

    /**
     * Create or reset one account to a known-good baseline; the state seeders layer their one deviation on top.
     * Attributes outside the model's fillable list are assigned directly rather than mass-assigned.
     */
    private function account(string $email, string $first, string $last, ?string $password = self::PASSWORD): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->first_name = $first;
        $user->last_name = $last;
        $user->password = $password;
        $user->email_verified_at ??= now();
        $user->is_active = true;
        $user->banned_at = null;
        $user->ban_reason = null;
        $user->require_password_reset = false;
        $user->two_factor_required = false;
        $user->save();

        return $user;
    }
}
