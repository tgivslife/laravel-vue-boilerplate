<?php

namespace App\Listeners\Auth;

use App\Services\Access\ImpersonationService;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Writes the impersonation-ended audit entry when a borrowed session is signed out by something other than ImpersonationService itself.
 *
 * Today that is Sanctum's AuthenticateSession: after the target's own password changes, its pin no longer matches
 * and the session is flushed before any controller runs - the marker would vanish without a trace.
 * The service's own teardowns (stop, abandon, actor cutoff) audit first and flag the request with SWAP_ATTRIBUTE, so they are skipped here.
 */
readonly class CloseImpersonationWindowOnLogout
{
    public function __construct(private Request $request, private ImpersonationService $impersonation)
    {
    }

    /**
     * Handle the event.
     */
    public function handle(Logout|CurrentDeviceLogout $event): void
    {
        if ((bool) $this->request->attributes->get(ImpersonationService::SWAP_ATTRIBUTE, false)) {
            return;
        }

        rescue(fn() => $this->impersonation->closeWindow($this->request, $event->user), report: true);
    }
}
