<?php

namespace App\Listeners\Auth;

use App\Services\Auth\SessionRegistry;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Drops the session registry row when a user logs out.
 *
 * Fires while the session still carries its pre-invalidation id, so the
 * row can be matched. Without this the row would linger until the lazy
 * liveness prune or the scheduled sweep collects it - harmless, but noisy.
 * The body is rescue()-wrapped: registry bookkeeping must never be the
 * reason a logout fails.
 */
readonly class ForgetSessionRegistryEntry
{
    public function __construct(
        private Request $request,
        private SessionRegistry $registry,
    ) {
    }

    /**
     * Handle the event.
     */
    public function handle(Logout $event): void
    {
        rescue(function (): void {
            if (!$this->request->hasSession()) {
                return;
            }

            $this->registry->forget($this->request->session()->getId());
        });
    }
}
