<?php

namespace App\Listeners\Auth;

use App\Models\AuthenticationLog;
use App\Models\User;
use App\Notifications\NewDeviceNotification;
use App\Services\Access\ImpersonationService;
use App\Support\Auth\LoginMethod;
use App\Support\Device;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Records successful logins and flags logins from unknown devices.
 *
 * Also maintains the denormalized last_login_at/last_login_ip summary on the users table: the log itself is purged
 * after the retention period, so the user row keeps a durable "last seen" that survives pruning.
 *
 * Session restorations are folded into the existing row: the remember-me recaller re-fires the Login event, so an active
 * session from the same device within the configured window only bumps last_activity_at instead of logging a new episode,
 * and does not refresh the last-login summary.
 *
 * The body is rescue()-wrapped: this listener observes authentication and must never be the reason a login fails.
 *
 * Impersonation swaps are ignored entirely: the Login event fired by the guard is a session changing hands, not the account owner signing in.
 * Recording it would write the admin's device into the target's history, corrupt the last-login summary, and mail the target a new-device alert.
 */
readonly class LogSuccessfulLogin
{
    public function __construct(private Request $request)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        if (!$event->user instanceof User || $this->isImpersonationSwap()) {
            return;
        }

        rescue(function () use ($event): void {
            /** @var User $user */
            $user = $event->user;

            if (!(bool) config('security.authentication_log.enabled', true)) {
                $this->rememberLastLogin($user);

                return;
            }

            $deviceId = Device::fingerprint($this->request);

            if ($this->touchRestoredSession($user, $deviceId)) {
                return;
            }

            $hasLoginHistory = $user->authentications()->successful()->exists();
            $isKnownDevice = $hasLoginHistory
                && $user->authentications()->successful()->fromDevice($deviceId)->exists();

            $log = $user->authentications()->create([
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->userAgent(),
                'device_id' => $deviceId,
                'device_name' => Device::name($this->request),
                'login_at' => now(),
                'login_successful' => true,
                'login_method' => LoginMethod::declared(),
                'last_activity_at' => now(),
            ]);

            $this->rememberLastLogin($user);

            /*
             * Only notify when there is history to compare against: a user's first recorded login
             * (including every existing user's first login after this feature ships) defines their known device
             * rather than alerting on it.
             */
            if ($hasLoginHistory && !$isKnownDevice && $user->canAuthenticate()) {
                $this->sendNewDeviceNotification($user, $log);
            }
        });
    }

    /**
     * Whether this request's guard event came from an impersonation identity swap.
     */
    private function isImpersonationSwap(): bool
    {
        return (bool) $this->request->attributes->get(ImpersonationService::SWAP_ATTRIBUTE, false);
    }

    /**
     * Maintain the denormalized last-login summary on the user row.
     *
     * Saved quietly and without timestamps: a login is account activity, not a profile update, so it must bump
     * neither updated_at nor fire model events.
     *
     * Signing in also withdraws a pending inactivity-closure notice: the pre-notice mail promises the account survives if its owner returns.
     */
    private function rememberLastLogin(User $user): void
    {
        User::withoutTimestamps(function () use ($user): void {
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $this->request->ip(),
                'inactivity_notice_sent_at' => null,
            ])->saveQuietly();
        });
    }

    /**
     * Fold a session restoration into its original row.
     *
     * Returns true when an active session from this device was recently alive, in which case only last_activity_at is bumped.
     */
    private function touchRestoredSession(User $user, string $deviceId): bool
    {
        $window = (int) config('security.authentication_log.session_restoration_window_minutes', 5);
        $cutoff = now()->subMinutes($window);

        $activeSession = $user->authentications()
            ->active()
            ->fromDevice($deviceId)
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '>', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_activity_at')->where('login_at', '>', $cutoff);
                    });
            })
            ->latest('login_at')
            ->first();

        $activeSession?->update(['last_activity_at' => now()]);

        return $activeSession !== null;
    }

    /**
     * Queue the new-device mail, capped per user by the configured rate limit.
     */
    private function sendNewDeviceNotification(User $user, AuthenticationLog $log): void
    {
        $config = config('security.authentication_log.new_device_notification');

        if (!(bool) ($config['enabled'] ?? true)) {
            return;
        }

        RateLimiter::attempt(
            key: 'auth-log:new-device:'.$user->getKey(),
            maxAttempts: (int) ($config['rate_limit']['max_attempts'] ?? 3),
            callback: static fn() => $user->notify(new NewDeviceNotification(
                deviceName: (string) $log->device_name,
                ipAddress: $log->ip_address,
                loginAt: $log->login_at,
                hasPassword: $user->getAttribute('password') !== null,
            )),
            decaySeconds: 60 * (int) ($config['rate_limit']['decay_minutes'] ?? 60),
        );
    }
}
