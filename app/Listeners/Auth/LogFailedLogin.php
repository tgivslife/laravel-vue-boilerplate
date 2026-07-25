<?php

namespace App\Listeners\Auth;

use App\Models\User;
use App\Support\Auth\LoginMethod;
use App\Support\Device;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;

/**
 * Records failed login attempts against existing accounts.
 *
 * Unknown emails resolve to no user and are deliberately not logged - there
 * is no account to attach them to. The event's credentials array (which
 * carries the attempted password) is never read. Individual failures are
 * logged but never mailed; the user is notified only when the lockout
 * threshold trips ({@see SendLockoutNotification}), which is what separates
 * a typo from deliberate guessing. The body is rescue()-wrapped: this
 * listener observes authentication and must never be the reason a login
 * attempt errors out.
 */
readonly class LogFailedLogin
{
    public function __construct(private Request $request)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(Failed $event): void
    {
        if (!(bool) config('security.authentication_log.enabled', true) || !$event->user instanceof User) {
            return;
        }

        rescue(function () use ($event): void {
            $event->user->authentications()->create([
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->userAgent(),
                'device_id' => Device::fingerprint($this->request),
                'device_name' => Device::name($this->request),
                'login_at' => now(),
                'login_successful' => false,
                'login_method' => LoginMethod::declared(),
            ]);
        });
    }
}
