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
 * Carries a single-use sign-in link to the user's mailbox.
 *
 * Queued, and ShouldBeEncrypted: the URL embeds the plaintext token, which exists nowhere else and must never be logged,
 * so the serialized payload is APP_KEY-encrypted in the queue backend, failed_jobs and Horizon, decrypted
 * only by the worker - as protected in transport as the hashed-at-rest token store (MagicLinkTokenHasher).
 *
 * The mail names the requesting device, since anyone can request a link for any address.
 * The user agent travels raw and is resolved here, when the mail renders on the worker: the parse costs tens of milliseconds,
 * and mail work of any kind on the request would make its duration depend on whether a user exists.
 *
 * `provisioning` swaps in the welcome copy for a link whose consumption will create the account.
 */
class MagicLinkNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
        private readonly bool $provisioning = false,
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
            ->subject(__($this->provisioning ? 'api.auth.magic_link.mail.welcome_subject' : 'api.auth.magic_link.mail.subject'))
            ->action(__('api.auth.magic_link.mail.action'), $this->url)
            ->markdown('mail.auth.magic-link', [
                'provisioning' => $this->provisioning,
                'deviceName' => Device::nameFromUserAgent($this->userAgent),
                'ipAddress' => $this->ipAddress ?? '-',
                'requestedAt' => $localizedRequestedAt,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
