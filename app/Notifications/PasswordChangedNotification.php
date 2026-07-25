<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their account password was changed.
 *
 * Sent for both change paths - the settings form and the forgot-password
 * reset - via the PasswordReset event. A silent password change is exactly
 * what an account takeover looks like, so the owner always hears about it.
 * Queued so the mail never adds latency to the request, and carries only
 * scalar snapshot data of the changing device.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
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
            ->subject(__('api.auth.password_changed.mail.subject'))
            ->markdown('mail.auth.password-changed', [
                'deviceName' => $this->deviceName,
                'ipAddress' => $this->ipAddress ?? '-',
                'changedAt' => $localizedChangedAt,
            ]);
    }
}
