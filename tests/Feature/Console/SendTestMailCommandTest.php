<?php

namespace Tests\Feature\Console;

use App\Notifications\AccountLockedNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Notifications\NewDeviceNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TwoFactorDisabledNotification;
use App\Notifications\TwoFactorEnabledNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendTestMailCommandTest extends TestCase
{
    public function test_sends_every_mail_type_by_default(): void
    {
        Notification::fake();

        $this->artisan('mail:send-test', ['email' => 'layout@example.com'])
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(MagicLinkNotification::class, 2);
        Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
        Notification::assertSentOnDemandTimes(ResetPasswordNotification::class, 1);
        Notification::assertSentOnDemandTimes(PasswordChangedNotification::class, 1);
        Notification::assertSentOnDemandTimes(NewDeviceNotification::class, 2);
        Notification::assertSentOnDemandTimes(AccountLockedNotification::class, 2);
        Notification::assertSentOnDemandTimes(TwoFactorEnabledNotification::class, 1);
        Notification::assertSentOnDemandTimes(TwoFactorDisabledNotification::class, 2);
    }

    public function test_sends_only_the_requested_type(): void
    {
        Notification::fake();

        $this->artisan('mail:send-test', ['email' => 'layout@example.com', '--type' => 'lockout'])
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(AccountLockedNotification::class, 1);
        Notification::assertSentOnDemandTimes(MagicLinkNotification::class, 0);
        Notification::assertSentOnDemandTimes(ResetPasswordNotification::class, 0);
        Notification::assertSentOnDemandTimes(NewDeviceNotification::class, 0);
    }

    public function test_rejects_an_invalid_email_address(): void
    {
        Notification::fake();

        $this->artisan('mail:send-test', ['email' => 'not-an-email'])
            ->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_rejects_an_unknown_mail_type(): void
    {
        Notification::fake();

        $this->artisan('mail:send-test', ['email' => 'layout@example.com', '--type' => 'nope'])
            ->assertFailed();

        Notification::assertNothingSent();
    }
}
