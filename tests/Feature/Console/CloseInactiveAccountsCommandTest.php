<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Notifications\InactivityClosedNotification;
use App\Notifications\InactivityNoticeNotification;
use App\Services\Settings\AppSettings;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The batch is planned from a chunk of loaded rows; the account changes between that load and its own closure.
     * Every closure condition must be decided on the row as it is then, not on the chunk's copy.
     *
     * @return array<string, array{0: Closure(): array<string, mixed>}>
     */
    public static function changesSinceThePlan(): array
    {
        return [
            'a sign-in' => [static fn(): array => ['last_login_at' => now(), 'inactivity_notice_sent_at' => null]],
            'a deactivation' => [static fn(): array => ['is_active' => false]],
            'a ban' => [static fn(): array => ['banned_at' => now()]],
        ];
    }

    /**
     * The closure decides under its lock, so the change lands at the moment its transaction begins: after the chunk
     * was loaded, before the row is re-read. Rechecked eligibility is what this proves, not lock contention.
     *
     * @param  Closure(): array<string, mixed>  $change
     */
    #[DataProvider('changesSinceThePlan')]
    public function test_a_change_between_the_plan_and_the_closure_withdraws_it(Closure $change): void
    {
        Notification::fake();
        $this->enablePolicy();
        $user = $this->noticedDaysAgo($this->lastLoggedInDaysAgo($this->createUser(), 400), 31);
        $this->changeOnce(TransactionBeginning::class, $user, $change);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 0 accounts; sent 0 closure notices.')
            ->expectsOutputToContain('Withdrawn 1 accounts')
            ->assertSuccessful();

        $this->assertFalse($user->refresh()->trashed());
        $this->assertDatabaseMissing('access_audit_logs', ['action' => 'user.inactivity_closed']);
        Notification::assertNothingSent();
    }

    /**
     * The notice phase has no transaction; the change lands as the chunk retrieves the row, before the stamp.
     * The conditional stamp finds nothing to stamp, and no notice promises a closure the change already withdrew.
     *
     * @param  Closure(): array<string, mixed>  $change
     */
    #[DataProvider('changesSinceThePlan')]
    public function test_a_change_between_the_plan_and_the_notice_withdraws_it(Closure $change): void
    {
        Notification::fake();
        $this->enablePolicy();
        $user = $this->lastLoggedInDaysAgo($this->createUser(), 400);
        $this->changeOnce('eloquent.retrieved: '.User::class, $user, $change);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 0 accounts; sent 0 closure notices.')
            ->expectsOutputToContain('Withdrawn 1 accounts')
            ->assertSuccessful();

        $this->assertNull($user->refresh()->inactivity_notice_sent_at);
        Notification::assertNothingSent();
    }

    /**
     * Write the change straight to the account's row the first time the event fires, as a concurrent request would.
     *
     * @param  Closure(): array<string, mixed>  $change
     */
    private function changeOnce(string $event, User $user, Closure $change): void
    {
        $applied = false;

        Event::listen($event, static function () use ($user, $change, &$applied): void {
            if (!$applied) {
                $applied = true;
                DB::table('users')->where('id', $user->getKey())->update($change());
            }
        });
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

    /**
     * The last active holder of a lockout permission is held back from both phases: closing them would leave nobody
     * able to administer that capability, and a notice would promise a closure the command will refuse.
     */
    public function test_the_last_active_holder_of_a_lockout_permission_is_held_back(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $holder = $this->noticedDaysAgo($this->lastLoggedInDaysAgo($this->holderOf('users.manage'), 400), 31);
        $unnoticedHolder = $this->lastLoggedInDaysAgo($this->holderOf('roles.manage'), 400);
        $other = $this->noticedDaysAgo($this->lastLoggedInDaysAgo($this->createUser(), 400), 31);

        $this->artisan('access:close-inactive-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('[Dry run] Would close 1 accounts and send 0 closure notices.')
            ->expectsOutputToContain('Held back 2 accounts')
            ->assertSuccessful();

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 1 accounts; sent 0 closure notices.')
            ->expectsOutputToContain('Held back 2 accounts')
            ->assertSuccessful();

        $this->assertTrue(User::withTrashed()->find($other->getKey())->trashed());
        $this->assertFalse($holder->refresh()->trashed());
        $this->assertNull($unnoticedHolder->refresh()->inactivity_notice_sent_at);
        $this->assertDatabaseMissing('access_audit_logs', [
            'action' => 'user.inactivity_closed',
            'subject_id' => $holder->getKey(),
        ]);
        Notification::assertNotSentTo($unnoticedHolder, InactivityNoticeNotification::class);
        Notification::assertSentOnDemandTimes(InactivityClosedNotification::class, 1);
    }

    /**
     * Two inactive holders are neither the last one when the run is planned, but closing the first makes the second
     * one: the retirement answers under the lock, so the second is held back rather than closed.
     */
    public function test_a_holder_who_becomes_the_last_one_during_the_run_is_held_back(): void
    {
        Notification::fake();
        $this->enablePolicy();
        $first = $this->noticedDaysAgo($this->lastLoggedInDaysAgo($this->holderOf('users.manage'), 400), 31);
        $second = $this->noticedDaysAgo($this->lastLoggedInDaysAgo($this->holderOf('users.manage'), 400), 31);

        $this->artisan('access:close-inactive-accounts')
            ->expectsOutputToContain('Closed 1 accounts; sent 0 closure notices.')
            ->expectsOutputToContain('Held back 1 accounts')
            ->assertSuccessful();

        $this->assertTrue(User::withTrashed()->find($first->getKey())->trashed());
        $this->assertFalse($second->refresh()->trashed());
        Notification::assertSentOnDemandTimes(InactivityClosedNotification::class, 1);
    }

    private function holderOf(string $permission): User
    {
        $user = $this->createUser();
        $user->givePermissionTo(
            config('permission.models.permission')::findOrCreate($permission, config('access.guard'))
        );

        return $user;
    }

    private function noticedDaysAgo(User $user, int $days): User
    {
        User::withoutTimestamps(function () use ($user, $days): void {
            $user->forceFill(['inactivity_notice_sent_at' => now()->subDays($days)])->saveQuietly();
        });

        return $user;
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
