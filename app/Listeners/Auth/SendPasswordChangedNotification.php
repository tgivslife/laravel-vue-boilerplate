<?php

namespace App\Listeners\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\Device;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;

/**
 * Mails the account owner whenever their password changes.
 *
 * Listens on the PasswordReset event, which both change paths fire: the
 * settings form (PasswordController) and the forgot-password reset
 * (PasswordResetService) - one listener covers both. The body is
 * rescue()-wrapped: this listener observes the change and must never be
 * the reason it fails.
 */
readonly class SendPasswordChangedNotification
{
    public function __construct(private Request $request)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(PasswordReset $event): void
    {
        if (!(bool) config('security.authentication_log.password_changed_notification.enabled', true)) {
            return;
        }

        if (!$event->user instanceof User) {
            return;
        }

        rescue(function () use ($event): void {
            $event->user->notify(
                new PasswordChangedNotification(
                    deviceName: Device::name($this->request),
                    ipAddress: $this->request->ip(),
                    changedAt: now(),
                )->locale(app()->getLocale())
            );
        });
    }
}
