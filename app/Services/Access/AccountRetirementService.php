<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The retirement mechanics shared by every door: the admin delete, the self-service delete and the inactivity closure.
 *
 * Severs every credential (API tokens, magic-link and invitation tokens, OIDC identity links, sessions, remember token),
 * tombstones the email ({uuid}@deleted.invalid, with the keyed hash kept for membership lookups) and soft-deletes the row.
 * Identity links are deleted, not left behind: a dead account must not squat its provider subject against the person the tombstone freed.
 * Sessions go after the caller's transaction commits - they live outside the database, so a refusal after this ran
 * could not bring them back - and a failed sweep is reported, not thrown: the account is already retired and its sessions authenticate nobody.
 *
 * Policy and atomicity stay with the caller: every door runs this inside AccessControlService's guarded transaction,
 * so no door can retire a lockout permission's last active holder.
 */
readonly class AccountRetirementService
{
    public function __construct(
        private SessionRegistry $sessionRegistry,
        private DeletedEmailHasher $deletedEmails,
    ) {
    }

    public function retire(User $user): void
    {
        $user->tokens()->delete();

        $user->magicLinkTokens()->delete();

        $user->identities()->delete();

        // Sessions live outside the database, so a rollback cannot bring them back: they go once the retirement is committed.
        // By then the account is gone and its sessions resolve to nobody, so a failed sweep is reported, never a failed request.
        DB::afterCommit(fn() => rescue(fn() => $this->sessionRegistry->destroyAll($user), report: true));

        User::withoutTimestamps(function () use ($user): void {
            $user->setRememberToken(Str::random(60));
            $user->forceFill([
                'deleted_email_hash' => $this->deletedEmails->hash($user->email),
                'email' => $this->deletedEmails->tombstoneAddress(),
            ]);
            $user->saveQuietly();
        });

        $user->delete();
    }
}
