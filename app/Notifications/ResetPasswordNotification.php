<?php

namespace App\Notifications;

use App\Support\Device;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries a single-use password-reset link to the user's mailbox; the token itself is the password broker's.
 *
 * Queued, and ShouldBeEncrypted: the URL embeds the token, so the payload is APP_KEY-encrypted in the queue backend,
 * failed_jobs and Horizon, decrypted only by the worker. The requesting device is named for the recipient, since
 * anyone can request a reset for any address, but resolved from the raw user agent only here, when the mail renders
 * on the worker: the parse is tens of milliseconds the request must not spend only when a user exists.
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $url,
        private readonly int $expiresInMinutes,
        private readonly string $userAgent,
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
                'deviceName' => Device::nameFromUserAgent($this->userAgent),
                'ipAddress' => $this->ipAddress ?? '-',
                'requestedAt' => $localizedRequestedAt,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
