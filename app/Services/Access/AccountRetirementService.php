<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Support\Str;

/**
 * The retirement mechanics shared by the admin delete and the self-service delete.
 *
 * Severs every credential (API tokens, magic-link and invitation tokens, OIDC identity links, session rows, remember token),
 * tombstones the email ({uuid}@deleted.invalid, with the keyed hash kept for membership lookups) and soft-deletes the row.
 * One code path on purpose: both doors must retire an account identically, whoever pulled the trigger.
 *
 * Identity links are deleted, not left behind: a dead account must not squat its provider subject,
 * or the person the tombstone freed could never provision or link that identity again.
 *
 * Policy and atomicity stay with the caller: the admin door runs this inside its guarded transaction
 * with its audit trail, the self-service door inside its own transaction plus the session logout.
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

        $this->sessionRegistry->destroyAll($user);

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
