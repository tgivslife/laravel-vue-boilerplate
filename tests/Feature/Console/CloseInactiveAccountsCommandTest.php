<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Notifications\InactivityClosedNotification;
use App\Notifications\InactivityNoticeNotification;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CloseInactiveAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function enablePolicy(int $inactiveDays = 365, int $noticeDays = 30): void
    {
        app(AppSettings::class)->set('inactivity_closure', [
            'enabled' => true,
            'inactive_days' => $inactiveDays,
            'notice_days' => $noticeDays,
        ]);
    }

    /**
     * Backdate the durable last-login summary without touching timestamps,
     * the same way the login listener maintains it.
     */
    private function lastLoggedInDaysAgo(User $user, int $days): User
    {
        User::withoutTimestamps(function () use ($user, $days): void {
            $user->forceFill(['last_login_at' => now()->subDays($days)])->saveQuietly();
        });

        return $user;
    }

    public function test_a_disabled_policy_does_nothing(): void
    {
        Notification::fake();
        $this->lastLoggedInDaysAgo($this->createUser(), 1000);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Inactivity closure is disabled; nothing to do.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_notice_is_sent_once_and_never_closes_the_account_early(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $user = $this->lastLoggedInDaysAgo($this->createUser(), 400);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 0 accounts; sent 1 closure notices.')
            ->assertSuccessful();

        // Stamped, warned, but alive: the closure phase must wait out the notice window.
        $this->assertNotNull($user->refresh()->inactivity_notice_sent_at);
        $this->assertFalse($user->trashed());
        Notification::assertSentTo($user, InactivityNoticeNotification::class);

        // A second run does not nag: the stamp keeps the notice single-shot.
        $this->artisan('access:close-inactive-accounts')->assertSuccessful();
        Notification::assertSentToTimes($user, InactivityNoticeNotification::class, 1);
    }

    public function test_recently_active_accounts_are_left_alone(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $this->lastLoggedInDaysAgo($this->createUser(), 100);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 0 accounts; sent 0 closure notices.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_noticed_account_is_retired_after_the_notice_window(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $user = $this->lastLoggedInDaysAgo($this->createUser(), 400);
        $originalEmail = $user->email;

        $this->artisan('access:close-inactive-accounts')->assertSuccessful();

        $this->travelTo(now()->addDays(31));

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 1 accounts; sent 0 closure notices.')
            ->assertSuccessful();

        // Retired through the shared path: soft-deleted with the email tombstoned.
        $user = User::withTrashed()->find($user->getKey());
        $this->assertTrue($user->trashed());
        $this->assertStringEndsWith('@deleted.invalid', $user->email);
        $this->assertNotNull($user->deleted_email_hash);

        $this->assertDatabaseHas('access_audit_logs', [
            'action' => 'user.inactivity_closed',
            'actor_id' => $user->getKey(),
            'subject_id' => $user->getKey(),
        ]);

        // The confirmation is routed to the pre-retirement address, not the tombstone.
        Notification::assertSentOnDemand(
            InactivityClosedNotification::class,
            fn(
                InactivityClosedNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable
            ): bool => $notifiable->routes['mail'] === $originalEmail,
        );
    }

    public function test_signing_in_after_the_notice_withdraws_the_closure(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $user = $this->lastLoggedInDaysAgo($this->createUser(), 400);

        $this->artisan('access:close-inactive-accounts')->assertSuccessful();

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        // The sign-in cleared the stamp, so the aged-notice closure never matches.
        $this->assertNull($user->refresh()->inactivity_notice_sent_at);

        $this->travelTo(now()->addDays(31));

        $this->artisan('access:close-inactive-accounts')->assertSuccessful();

        $this->assertFalse($user->refresh()->trashed());
        Notification::assertNotSentTo($user, InactivityClosedNotification::class);
    }

    public function test_a_dry_run_reports_both_phases_without_touching_anything(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $unnoticed = $this->lastLoggedInDaysAgo($this->createUser(), 400);
        $noticed = $this->lastLoggedInDaysAgo($this->createUser(), 400);
        User::withoutTimestamps(function () use ($noticed): void {
            $noticed->forceFill(['inactivity_notice_sent_at' => now()->subDays(31)])->saveQuietly();
        });

        $this->artisan('access:close-inactive-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('[Dry run] Would close 1 accounts and send 1 closure notices.')
            ->assertSuccessful();

        // Nothing moved: no mail, no stamp, no retirement.
        Notification::assertNothingSent();
        $this->assertNull($unnoticed->refresh()->inactivity_notice_sent_at);
        $this->assertFalse($noticed->refresh()->trashed());
    }

    public function test_deactivated_and_banned_accounts_are_skipped(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $deactivated = $this->lastLoggedInDaysAgo($this->createUser(['is_active' => false]), 400);
        $banned = $this->lastLoggedInDaysAgo($this->createUser(['banned_at' => now()->subDays(400)]), 400);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 0 accounts; sent 0 closure notices.')
            ->assertSuccessful();

        $this->assertNull($deactivated->refresh()->inactivity_notice_sent_at);
        $this->assertNull($banned->refresh()->inactivity_notice_sent_at);
        Notification::assertNothingSent();
    }

    public function test_accounts_that_never_signed_in_are_measured_from_creation(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $user = $this->createUser(['last_login_at' => null]);
        $user->forceFill(['created_at' => now()->subDays(400)])->saveQuietly();

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 0 accounts; sent 1 closure notices.')
            ->assertSuccessful();

        Notification::assertSentTo($user, InactivityNoticeNotification::class);
    }
}
