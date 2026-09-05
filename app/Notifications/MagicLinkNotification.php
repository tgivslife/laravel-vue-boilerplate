<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries a single-use sign-in link to the user's mailbox.
 *
 * Queued deliberately: dispatching the mail inline would make the request endpoint's response time depend on
 * whether a user exists, undoing its enumeration resistance.
 * The URL embeds the plaintext token, which exists nowhere else - it must never be logged.
 * ShouldBeEncrypted so that plaintext token never sits readable in the queue backend, failed_jobs or Horizon:
 * the serialized payload is APP_KEY-encrypted at rest and decrypted only by the worker, keeping the transport
 * as protected as the hashed-at-rest token store (MagicLinkTokenHasher).
 *
 * Carries a scalar snapshot of the requesting device: the request endpoint is open to anyone, so the mail shows
 * the recipient which device asked for the link, letting them judge the "ignore this email" advice.
 *
 * `provisioning` swaps in the welcome copy for a link whose consumption will create the account (magic-link self-provisioning).
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
        private readonly string $deviceName,
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
                'deviceName' => $this->deviceName,
                'ipAddress' => $this->ipAddress ?? '-',
                'requestedAt' => $localizedRequestedAt,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
