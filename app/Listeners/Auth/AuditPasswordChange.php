<?php

namespace App\Listeners\Auth;

use App\Models\User;
use App\Services\Access\AccessAuditor;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Writes the credential-lifecycle audit entry for password changes.
 *
 * Listens on the PasswordReset event, which both self-service paths fire: the settings form (PasswordController) and
 * the forgot-password reset (PasswordResetService) - one listener covers both, with the owner as actor.
 * The admin-forced variant is audited separately (user.password_reset_forced).
 * No snapshots: there is nothing non-sensitive to diff, so the entry is a headline-only event.
 * The body is rescue()-wrapped: this listener observes the change and must never be the reason it fails.
 */
readonly class AuditPasswordChange
{
    public function __construct(private AccessAuditor $auditor)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(PasswordReset $event): void
    {
        if (!$event->user instanceof User) {
            return;
        }

        rescue(function () use ($event): void {
            $this->auditor->record($event->user, 'user.password_changed', $event->user, null, null);
        });
    }
}
