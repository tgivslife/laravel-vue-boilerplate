<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their account was temporarily locked after repeated
 * failed sign-in attempts.
 *
 * Sent on the Lockout event rather than per failed attempt: crossing the
 * lockout threshold is what separates an owner's typo (never notified)
 * from deliberate password guessing (one mail per lockout episode).
 * Queued so the mail never adds latency to the login request, and carries
 * only scalar snapshot data so the job cannot break on pruned rows.
 */
class AccountLockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $deviceName,
        private readonly ?string $ipAddress,
        private readonly CarbonInterface $unlockAt,
        private readonly bool $hasPassword = true,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $localizedUnlockAt = $this->unlockAt->copy()
            ->setTimezone(config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('LLL');

        return (new MailMessage)
            ->subject(__('api.auth.lockout.mail.subject'))
            ->markdown('mail.auth.account-locked', [
                'deviceName' => $this->deviceName,
                'ipAddress' => $this->ipAddress ?? '-',
                'unlockAt' => $localizedUnlockAt,
                'hasPassword' => $this->hasPassword,
            ]);
    }
}
