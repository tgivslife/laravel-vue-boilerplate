<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Notifications\NewDeviceNotification;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthenticationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_logged(): void
    {
        $user = $this->createUser();

        $this->login($user)->assertOk();

        $this->assertDatabaseCount('authentication_logs', 1);
        $this->assertDatabaseHas('authentication_logs', [
            'authenticatable_id' => $user->id,
            // The enforced morph map (config/access.php) stores aliases.
            'authenticatable_type' => $user->getMorphClass(),
            'login_successful' => true,
            'logout_at' => null,
        ]);

        $log = $user->authentications()->first();
        $this->assertNotNull($log->device_id);
        $this->assertNotNull($log->device_name);
        $this->assertNotNull($log->login_at);
        $this->assertNotNull($log->ip_address);
    }

    public function test_failed_login_against_an_existing_account_is_logged(): void
    {
        $user = $this->createUser();

        $this->login($user, 'wrong-password')->assertStatus(401);

        $this->assertDatabaseHas('authentication_logs', [
            'authenticatable_id' => $user->id,
            'login_successful' => false,
        ]);
    }

    public function test_failed_login_with_unknown_email_is_not_logged(): void
    {
        $this->withHeader('Referer', config('app.url'))->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertStatus(401);

        $this->assertDatabaseCount('authentication_logs', 0);
    }

    public function test_logout_closes_the_active_log_entry(): void
    {
        $user = $this->createUser();
        $this->login($user);

        $this->postJson('/api/logout')->assertNoContent();

        $this->assertNotNull($user->authentications()->first()->logout_at);
    }

    public function test_relogin_event_within_the_restoration_window_does_not_duplicate_the_entry(): void
    {
        $user = $this->createUser();
        $this->login($user);

        // The remember-me recaller re-fires Login for an already-recorded
        // session; the listener resolves the same request, so the active
        // row is touched instead of duplicated.
        event(new Login('web', $user, true));

        $this->assertDatabaseCount('authentication_logs', 1);
        $this->assertNotNull($user->authentications()->first()->last_activity_at);
    }

    public function test_first_ever_login_does_not_notify(): void
    {
        Notification::fake();
        $user = $this->createUser();

        $this->login($user)->assertOk();

        Notification::assertNothingSent();
    }

    public function test_login_from_a_known_device_does_not_notify(): void
    {
        Notification::fake();
        $user = $this->createUser();

        $this->login($user)->assertOk();
        $this->logout();
        $this->login($user)->assertOk();

        Notification::assertNothingSent();
    }

    public function test_password_logins_record_their_method(): void
    {
        $user = $this->createUser();

        // Failed first: a successful login would leave the test session
        // authenticated and turn the second attempt into a 409.
        $this->login($user, 'wrong-password')->assertStatus(401);
        $this->login($user)->assertStatus(200);

        $methods = $user->authentications()->orderBy('id')->pluck('login_method');
        $this->assertSame(['password', 'password'], $methods->all());
    }

    public function test_login_from_a_new_device_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createUser();

        $this->login($user)->assertOk();
        $this->logout();
        $this->login($user, userAgent: 'OtherBrowser/9.0 (X11; Linux x86_64)')->assertOk();

        Notification::assertSentTo($user, NewDeviceNotification::class);
    }

    public function test_new_device_notifications_are_rate_limited_per_user(): void
    {
        config(['security.authentication_log.new_device_notification.rate_limit.max_attempts' => 1]);
        Notification::fake();
        $user = $this->createUser();

        $this->login($user)->assertOk();
        $this->logout();
        $this->login($user, userAgent: 'SecondBrowser/2.0')->assertOk();
        $this->logout();
        $this->login($user, userAgent: 'ThirdBrowser/3.0')->assertOk();

        Notification::assertSentToTimes($user, NewDeviceNotification::class, 1);
    }

    public function test_account_that_cannot_authenticate_is_not_notified(): void
    {
        Notification::fake();
        $user = $this->createUser();

        // Seed device history, then ban the account: the banned login has valid credentials,
        // so it stays visible in the log - but as a failure, since no session was established - and no mail goes out.
        $this->login($user)->assertOk();
        $this->logout();
        $user->forceFill(['banned_at' => now(), 'ban_reason' => 'test'])->save();

        $this->login($user, userAgent: 'OtherBrowser/9.0')->assertStatus(403);

        Notification::assertNothingSent();
        $this->assertSame(1, $user->authentications()->successful()->count());
        $this->assertSame(1, $user->authentications()->where('login_successful', false)->count());
    }

    public function test_logging_can_be_disabled(): void
    {
        config(['security.authentication_log.enabled' => false]);
        $user = $this->createUser();

        $this->login($user)->assertOk();

        $this->assertDatabaseCount('authentication_logs', 0);
    }

    public function test_successful_login_updates_the_last_login_summary(): void
    {
        $user = $this->createUser();
        $originalUpdatedAt = $user->updated_at;
        $this->travel(1)->minute();

        $this->login($user)->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('127.0.0.1', $user->last_login_ip);
        // A login is account activity, not a profile update.
        $this->assertTrue($user->updated_at->equalTo($originalUpdatedAt));
    }

    public function test_failed_login_does_not_update_the_last_login_summary(): void
    {
        $user = $this->createUser();

        $this->login($user, 'wrong-password')->assertStatus(401);

        $this->assertNull($user->refresh()->last_login_at);
    }

    public function test_session_restoration_does_not_refresh_the_last_login_summary(): void
    {
        $user = $this->createUser();
        $this->login($user)->assertOk();
        $firstLoginAt = $user->refresh()->last_login_at;

        $this->travel(2)->minutes();
        event(new Login('web', $user, true));

        $this->assertTrue($user->refresh()->last_login_at->equalTo($firstLoginAt));
    }

    public function test_last_login_summary_updates_even_when_logging_is_disabled(): void
    {
        config(['security.authentication_log.enabled' => false]);
        $user = $this->createUser();

        $this->login($user)->assertOk();

        $this->assertDatabaseCount('authentication_logs', 0);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_purge_command_deletes_only_entries_past_retention(): void
    {
        config(['security.authentication_log.retention_days' => 365]);
        $user = $this->createUser();
        $user->authentications()->create(['login_at' => now()->subDays(400), 'login_successful' => true]);
        $user->authentications()->create(['login_at' => now()->subDays(10), 'login_successful' => true]);

        $this->artisan('auth:purge-authentication-logs')->assertSuccessful();

        $this->assertDatabaseCount('authentication_logs', 1);
        $this->assertSame(10, (int) now()->diffInDays($user->authentications()->first()->login_at, true));
    }

    public function test_individual_failed_logins_do_not_notify(): void
    {
        Notification::fake();
        $user = $this->createUser();

        $this->login($user, 'wrong-password')->assertStatus(401);

        Notification::assertNothingSent();
    }

    public function test_lockout_notifies_the_user_once_per_episode(): void
    {
        config(['security.lockout.enabled' => true, 'security.lockout.max_attempts' => 5]);
        Notification::fake();
        $user = $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->login($user, 'wrong-password')->assertStatus(401);
        }

        // Every blocked attempt fires the Lockout event; only one mail leaves.
        $this->login($user, 'wrong-password')->assertStatus(423);
        $this->login($user, 'wrong-password')->assertStatus(423);

        Notification::assertSentToTimes($user, AccountLockedNotification::class, 1);
    }

    public function test_lockout_notification_can_be_disabled(): void
    {
        config([
            'security.lockout.enabled' => true,
            'security.lockout.max_attempts' => 5,
            'security.authentication_log.lockout_notification.enabled' => false,
        ]);
        Notification::fake();
        $user = $this->createUser();

        for ($i = 0; $i < 6; $i++) {
            $this->login($user, 'wrong-password');
        }

        Notification::assertNothingSent();
    }

    public function test_lockout_for_an_unknown_email_sends_nothing(): void
    {
        config(['security.lockout.enabled' => true, 'security.lockout.max_attempts' => 5]);
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->withHeader('Referer', config('app.url'))->postJson('/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);
        }

        Notification::assertNothingSent();
    }

    /**
     * Log in through the endpoint as a first-party request, optionally from
     * a specific device (user agent). The header persists for subsequent
     * requests in the test, so logout comes from the same "device".
     */
    private function login(User $user, string $password = 'password', ?string $userAgent = null): TestResponse
    {
        $this->withHeader('Referer', config('app.url'));

        if ($userAgent !== null) {
            $this->withHeader('User-Agent', $userAgent);
        }

        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    /**
     * Log out and drop the memoized guard so the next request re-resolves
     * authentication state, as in production.
     */
    private function logout(): void
    {
        $this->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
    }
}
