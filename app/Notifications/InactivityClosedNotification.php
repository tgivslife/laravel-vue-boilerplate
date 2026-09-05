<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the owner their account was closed after the announced inactivity period.
 *
 * By the time this is sent the account is already retired and its email tombstoned, so the command routes it
 * on demand (Notification::route) to the address it snapshotted before retirement - it never rides on the dead user row.
 */
class InactivityClosedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject(__('api.auth.inactivity.closed.mail.subject'))
            ->markdown('mail.auth.inactivity-closed');
    }
}
