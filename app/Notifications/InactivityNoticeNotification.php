<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns the owner of a long-inactive account that it is scheduled for closure.
 *
 * Sent once per closure episode (stamped in users.inactivity_notice_sent_at) by the scheduled access:close-inactive-accounts command;
 * signing in before the announced date withdraws the closure.
 * Queued, and carries only the scalar closure date so the job cannot break on rows that change between dispatch and delivery.
 */
class InactivityNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly CarbonInterface $closureDate,
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
        $localizedClosureDate = $this->closureDate->copy()
            ->setTimezone(config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('LL');

        return (new MailMessage)
            ->subject(__('api.auth.inactivity.notice.mail.subject'))
            ->markdown('mail.auth.inactivity-notice', [
                'closureDate' => $localizedClosureDate,
            ]);
    }
}
