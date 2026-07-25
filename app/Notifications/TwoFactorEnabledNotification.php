<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user two-factor authentication was enabled on their account.
 *
 * Enabling is almost always the owner's own act, but a hijacked session enrolling an attacker's authenticator would
 * lock the owner out at their next login - so the owner always hears about it while their session still works.
 * Queued so the mail never adds latency to the request, and carries only scalar snapshot data of the enrolling device.
 */
class TwoFactorEnabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $deviceName,
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
            ->subject(__('api.auth.two_factor_enabled.mail.subject'))
            ->markdown('mail.auth.two-factor-enabled', [
                'deviceName' => $this->deviceName,
                'ipAddress' => $this->ipAddress ?? '-',
                'changedAt' => $localizedChangedAt,
            ]);
    }
}
