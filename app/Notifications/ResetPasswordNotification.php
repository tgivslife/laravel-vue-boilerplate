<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries a single-use password-reset link to the user's mailbox.
 *
 * Queued deliberately: dispatching the mail inline would make the
 * forgot-password endpoint's response time depend on whether a user exists,
 * undoing its enumeration resistance. Token creation, hashing, expiry and
 * single-use semantics live in the framework's password broker; this class
 * only carries the built URL.
 *
 * Carries a scalar snapshot of the requesting device: the forgot-password
 * endpoint is open to anyone, so the mail shows the recipient which device
 * asked for the reset, letting them judge the "ignore this email" advice.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $url,
        private readonly int $expiresInMinutes,
        private readonly string $deviceName,
        private readonly ?string $ipAddress,
        private readonly CarbonInterface $requestedAt,
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
        $localizedRequestedAt = $this->requestedAt->copy()
            ->setTimezone(config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('LLL');

        return (new MailMessage)
            ->subject(__('api.auth.password_reset.mail.subject'))
            ->action(__('api.auth.password_reset.mail.action'), $this->url)
            ->markdown('mail.auth.reset-password', [
                'deviceName' => $this->deviceName,
                'ipAddress' => $this->ipAddress ?? '-',
                'requestedAt' => $localizedRequestedAt,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
