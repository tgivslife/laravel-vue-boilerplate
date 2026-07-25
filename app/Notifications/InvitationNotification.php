<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries a first-sign-in invitation link to an admin-created account's mailbox.
 *
 * Queued like every auth mail; the URL embeds the plaintext token, which exists nowhere else - it must never be logged.
 * Unlike MagicLinkNotification there is no requesting-device snapshot: the mail is admin-initiated, so the
 * requesting device would name the admin's browser, not anything the recipient can judge.
 *
 * `requiresPassword` swaps in the copy for deployments where password login is the only door: the consumed
 * link lands the user in front of the choose-your-password gate before the app opens up.
 */
class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $url,
        private readonly int $expiresInDays,
        private readonly bool $requiresPassword,
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
        return (new MailMessage)
            ->subject(__('api.auth.invitation.mail.subject', ['app' => config('app.name')]))
            ->action(__('api.auth.invitation.mail.action'), $this->url)
            ->markdown('mail.auth.invitation', [
                'requiresPassword' => $this->requiresPassword,
                'expiresInDays' => $this->expiresInDays,
            ]);
    }
}
