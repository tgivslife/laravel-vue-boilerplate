<?php

namespace App\Listeners\Auth;

use App\Models\AuthenticationLog;
use App\Models\User;
use App\Services\Access\ImpersonationService;
use App\Support\Device;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Closes the active authentication log row on logout.
 *
 * The row is matched by device fingerprint first and by IP + user agent as
 * a fallback; if neither matches (e.g. the row was already purged) the
 * logout is simply not recorded. The body is rescue()-wrapped: this
 * listener observes authentication and must never be the reason a logout
 * fails.
 *
 * Impersonation swaps are ignored: the guard-level Logout fired while a
 * borrowed session is torn down is not the account owner leaving, and the
 * IP + user-agent fallback could even close a row belonging to one of the
 * target's own devices.
 */
readonly class LogSuccessfulLogout
{
    public function __construct(private Request $request)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(Logout $event): void
    {
        if (!(bool) config('security.authentication_log.enabled', true)
            || !$event->user instanceof User
            || (bool) $this->request->attributes->get(ImpersonationService::SWAP_ATTRIBUTE, false)
        ) {
            return;
        }

        rescue(function () use ($event): void {
            $log = $this->findActiveLog($event->user);

            $log?->update([
                'logout_at' => now(),
                'last_activity_at' => now(),
            ]);
        });
    }

    /**
     * Locate the open row for the device this logout came from.
     */
    private function findActiveLog(User $user): ?AuthenticationLog
    {
        return $user->authentications()
            ->active()
            ->fromDevice(Device::fingerprint($this->request))
            ->latest('login_at')
            ->first()
            ?? $user->authentications()
                ->active()
                ->where('ip_address', $this->request->ip())
                ->where('user_agent', $this->request->userAgent())
                ->latest('login_at')
                ->first();
    }
}
