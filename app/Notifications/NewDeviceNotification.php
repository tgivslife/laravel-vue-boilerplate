<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the user their account was signed in to from an unknown device.
 *
 * Queued so the mail never adds latency to the login request. Carries only
 * scalar snapshot data (never the AuthenticationLog model): the log row may
 * be purged before the queue drains, and a serialized model reference would
 * make the job fail instead of delivering the alert.
 */
class NewDeviceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $deviceName,
        private readonly ?string $ipAddress,
        private readonly CarbonInterface $loginAt,
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
        $localizedLoginAt = $this->loginAt->copy()
            ->setTimezone(config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('LLL');

        return (new MailMessage)
            ->subject(__('api.auth.new_device.mail.subject'))
            ->markdown('mail.auth.new-device', [
                'deviceName' => $this->deviceName,
                'ipAddress' => $this->ipAddress ?? '-',
                'loginAt' => $localizedLoginAt,
                'hasPassword' => $this->hasPassword,
            ]);
    }
}
