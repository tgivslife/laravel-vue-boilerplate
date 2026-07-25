<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user two-factor authentication was disabled on their account.
 *
 * Covers both the self-service disable (with a snapshot of the disabling device) and the administrative reset
 * (no device facts - the admin's browser is irrelevant to the owner).
 * A silent disable is exactly what an account takeover looks like, so the owner always hears about it.
 * Queued so the mail never adds latency to the request.
 */
class TwoFactorDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly bool $byAdministrator,
        private readonly ?string $deviceName,
        private readonly ?string $ipAddress,
        private readonly CarbonInterface $changedAt,
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
        $localizedChangedAt = $this->changedAt->copy()
            ->setTimezone(config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('LLL');

        return (new MailMessage)
            ->subject(__('api.auth.two_factor_disabled.mail.subject'))
            ->markdown('mail.auth.two-factor-disabled', [
                'byAdministrator' => $this->byAdministrator,
                'deviceName' => $this->deviceName ?? '-',
                'ipAddress' => $this->ipAddress ?? '-',
                'changedAt' => $localizedChangedAt,
            ]);
    }
}
