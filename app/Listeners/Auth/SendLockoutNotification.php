<?php

namespace App\Listeners\Auth;

use App\Http\Payloads\Auth\LoginPayload;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Services\Auth\LoginRateLimiter;
use App\Support\Device;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mails the account owner when the login lockout trips.
 *
 * One mail per lockout episode: AuthService fires the Lockout event on
 * every blocked attempt, so sends are deduplicated per user for the
 * lockout duration. Only existing accounts resolve to a mail - the
 * attacker-facing response is identical either way, so this stays
 * enumeration-safe. The body is rescue()-wrapped: this listener observes
 * authentication and must never be the reason a login attempt errors out.
 */
readonly class SendLockoutNotification
{
    public function __construct(private LoginRateLimiter $limiter)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(Lockout $event): void
    {
        if (!(bool) config('security.authentication_log.lockout_notification.enabled', true)) {
            return;
        }

        rescue(function () use ($event): void {
            $email = (string) $event->request->input('email');
            $user = User::query()->where('email', $email)->first();

            if (!$user?->canAuthenticate()) {
                return;
            }

            /*
             * Only email and IP feed the throttle key; the payload exists
             * solely to ask the limiter how long this lockout has left.
             */
            $payload = new LoginPayload(
                email: $email,
                password: '',
                remember: false,
                ip: (string) $event->request->ip(),
            );
            $lockoutSeconds = $this->limiter->availableIn($payload);

            RateLimiter::attempt(
                key: 'auth-log:lockout:'.$user->getKey(),
                maxAttempts: 1,
                callback: fn() => $user->notify(new AccountLockedNotification(
                    deviceName: Device::name($event->request),
                    ipAddress: $event->request->ip(),
                    unlockAt: now()->addSeconds($lockoutSeconds),
                    hasPassword: $user->getAttribute('password') !== null,
                )),
                decaySeconds: max(60, $lockoutSeconds),
            );
        });
    }
}
