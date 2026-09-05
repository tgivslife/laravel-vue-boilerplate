<?php

namespace App\Console\Commands\Ops;

use App\Notifications\AccountLockedNotification;
use App\Notifications\InactivityClosedNotification;
use App\Notifications\InactivityNoticeNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Notifications\NewDeviceNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TwoFactorDisabledNotification;
use App\Notifications\TwoFactorEnabledNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationDispatcher;

/**
 * Sends sample transactional mails to an arbitrary address for reviewing email layouts in a real client.
 *
 * Mails are sent on demand (no user record involved) and synchronously, bypassing the queue, so the result lands
 * immediately via whatever mailer is configured.
 * Sample data uses documentation addresses; the link-carrying mails (magic link, invitation, password reset) hold
 * dummy tokens that cannot be consumed.
 */
#[Signature('mail:send-test
    {email : Address to deliver the test mails to}
    {--type=all : Which mail to send (all, magic-link, magic-link-signup, invitation, invitation-password, password-reset, password-changed, '.
    'new-device, new-device-passwordless, lockout, lockout-passwordless, two-factor-enabled, two-factor-disabled, two-factor-disabled-admin, '.
    'inactivity-notice, inactivity-closed)}
    {--locale= : Locale to render the mails in (defaults to app.locale)}')]
#[Description('Send sample transactional mails for reviewing email layouts')]
class SendTestMailCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        $mails = $this->sampleMails();
        $type = (string) $this->option('type');

        if ($type !== 'all') {
            if (!isset($mails[$type])) {
                $this->error("Unknown mail type '{$type}'. Available: all, ".implode(', ', array_keys($mails)).'.');

                return self::FAILURE;
            }

            $mails = [$type => $mails[$type]];
        }

        $locale = (string) ($this->option('locale') ?? config('app.locale'));

        foreach ($mails as $name => $notification) {
            NotificationDispatcher::route('mail', $email)->notifyNow($notification->locale($locale));

            $this->info("Sent '{$name}' to {$email}.");
        }

        $this->comment("Delivered via the '".config('mail.default')."' mailer in locale '{$locale}'.");

        return self::SUCCESS;
    }

    /**
     * One representative notification per mail layout, keyed by type name.
     *
     * @return array<string, Notification>
     */
    private function sampleMails(): array
    {
        $deviceName = 'Windows 11 / Chrome 149.0';
        $ipAddress = '203.0.113.42';

        return [
            'magic-link' => new MagicLinkNotification(
                url: url('/auth/magic/verify?token=sample-token-for-layout-testing'),
                expiresInMinutes: 15,
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                requestedAt: now(),
            ),
            'magic-link-signup' => new MagicLinkNotification(
                url: url('/auth/magic/verify?token=sample-token-for-layout-testing&signup=1'),
                expiresInMinutes: 15,
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                requestedAt: now(),
                provisioning: true,
            ),
            'invitation' => new InvitationNotification(
                url: url('/auth/magic/verify?token=sample-token-for-layout-testing&invite=1'),
                expiresInDays: (int) config('security.invitations.ttl_days', 7),
                requiresPassword: false,
            ),
            'invitation-password' => new InvitationNotification(
                url: url('/auth/magic/verify?token=sample-token-for-layout-testing&invite=1'),
                expiresInDays: (int) config('security.invitations.ttl_days', 7),
                requiresPassword: true,
            ),
            'password-reset' => new ResetPasswordNotification(
                url: url('/auth/password/reset?token=sample-token-for-layout-testing&email=layout%40example.com'),
                expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                requestedAt: now(),
            ),
            'password-changed' => new PasswordChangedNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                changedAt: now(),
            ),
            'new-device' => new NewDeviceNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                loginAt: now(),
                hasPassword: true,
            ),
            'new-device-passwordless' => new NewDeviceNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                loginAt: now(),
                hasPassword: false,
            ),
            'lockout' => new AccountLockedNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                unlockAt: now()->addMinutes((int) config('security.lockout.duration_minutes', 15)),
                hasPassword: true,
            ),
            'lockout-passwordless' => new AccountLockedNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                unlockAt: now()->addMinutes((int) config('security.lockout.duration_minutes', 15)),
                hasPassword: false,
            ),
            'two-factor-enabled' => new TwoFactorEnabledNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                changedAt: now(),
            ),
            'two-factor-disabled' => new TwoFactorDisabledNotification(
                byAdministrator: false,
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                changedAt: now(),
            ),
            'two-factor-disabled-admin' => new TwoFactorDisabledNotification(
                byAdministrator: true,
                deviceName: null,
                ipAddress: null,
                changedAt: now(),
            ),
            'inactivity-notice' => new InactivityNoticeNotification(
                closureDate: now()->addDays(30),
            ),
            'inactivity-closed' => new InactivityClosedNotification,
        ];
    }
}
