<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signing in as another account: a same-session identity swap, bracketed by audit entries.
 *
 * The borrowed session carries a marker (actor id + start time) that the resource layer reads for the banner and
 * EnsureNotImpersonating reads to keep access administration, token and credential surfaces closed while identity is borrowed.
 * Both directions of the swap regenerate the session id; the marker is what survives.
 * The target never receives a remember token, so nothing outlives the borrowed session itself.
 *
 * Tier rule (strict): targets holding the super-admin role or any privileged permission (AccessScope::isTopTier())
 * may only be impersonated by super admins, becoming a top-tier account requires being top-tier.
 * Scope dimensions veto out-of-reach targets like every other per-user action, so scoped deployments bound impersonation for free.
 */
readonly class ImpersonationService
{
    /**
     * Request attribute marking an in-flight identity swap.
     * The authentication-log listeners bail on it: guard-level Login/Logout events fired by a swap are bookkeeping,
     * not the account owner signing in - recording them would pollute the target's login history with the admin's
     * device and mail them a new-device alert.
     */
    public const string SWAP_ATTRIBUTE = 'impersonation.swap';

    private const string SESSION_KEY = 'impersonation';

    public function __construct(
        private AccessScope $access,
        private AccessAuditor $auditor,
    ) {
    }

    /**
     * Swap the session's identity to the target.
     */
    public function start(User $actor, User $target, Request $request): void
    {
        if ($this->state($request) !== null) {
            throw ValidationException::withMessages([
                'user' => __('api.access.impersonation_nested'),
            ]);
        }

        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => __('api.access.impersonation_self'),
            ]);
        }

        if (!$this->access->allowsRecord($actor, $target, 'view')) {
            throw new NotFoundHttpException;
        }

        if (!$target->canAuthenticate()) {
            throw ValidationException::withMessages([
                'user' => __('api.access.impersonation_target_ineligible'),
            ]);
        }

        if (!$this->access->isSuperAdmin($actor) && $this->access->isTopTier($target)) {
            throw ValidationException::withMessages([
                'user' => __('api.access.impersonation_above_tier'),
            ]);
        }

        // Written while the request still answers as the actor, so attribution needs no marker.
        // No snapshots: these are point-in-time events, and the subject names the account.
        $this->auditor->record($actor, 'user.impersonation_started', $target, null, null);

        $this->swapTo($target, $request);

        $request->session()->put(self::SESSION_KEY, [
            'actor_id' => (int) $actor->getKey(),
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * End the swap and restore the actor.
     *
     * The actor is re-resolved strictly: an admin deactivated, banned or deleted mid-impersonation is not restored,
     * the session is destroyed outright, leaving no one signed in.
     */
    public function stop(Request $request): ?User
    {
        $state = $this->state($request);

        if ($state === null) {
            throw ValidationException::withMessages([
                'user' => __('api.access.impersonation_not_active'),
            ]);
        }

        /** @var User|null $actor */
        $actor = User::withTrashed()->find($state['actor_id']);

        $this->auditEnd($actor, $request->user());

        $request->session()->forget(self::SESSION_KEY);

        if ($actor === null || $actor->trashed() || !$actor->canAuthenticate()) {
            $this->destroySession($request);

            return null;
        }

        $this->swapTo($actor, $request);

        return $actor;
    }

    /**
     * Tear down a borrowed session as part of a full sign-out - a logout request, or the
     * mid-impersonation ineligibility cutoff (EnsureUserCanAuthenticate). The audit window is
     * closed with its ended entry before the session is destroyed, so no impersonation ever ends
     * without a trace.
     *
     * Returns false when the session is not impersonating: the caller performs its ordinary logout.
     */
    public function abandon(Request $request): bool
    {
        $state = $this->state($request);

        if ($state === null) {
            return false;
        }

        $this->auditEnd(User::withTrashed()->find($state['actor_id']), $request->user());

        $request->session()->forget(self::SESSION_KEY);
        $this->destroySession($request);

        return true;
    }

    /**
     * Close the audit window with its ended entry, when both parties still resolve.
     */
    private function auditEnd(?User $actor, ?Authenticatable $target): void
    {
        if ($actor !== null && $target instanceof User) {
            $this->auditor->record($actor, 'user.impersonation_ended', $target, null, null);
        }
    }

    /**
     * Destroy the borrowed session outright, leaving no one signed in.
     *
     * logoutCurrentDevice() rather than logout(): the current user is the impersonation target, and a full logout
     * would cycle their remember token, silently signing the target out of their own remembered devices.
     * The borrowed session never held a remember cookie, so invalidation alone kills it.
     * It also fires CurrentDeviceLogout instead of Logout, keeping the target's authentication log untouched; SWAP_ATTRIBUTE guards future listeners.
     */
    private function destroySession(Request $request): void
    {
        $request->attributes->set(self::SWAP_ATTRIBUTE, true);

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * The session's impersonation marker, if identity is currently borrowed.
     *
     * @return array{actor_id: int, started_at: string}|null
     */
    public function state(Request $request): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        // Shape-checked, so anything but a well-formed marker reads as "not impersonating" rather than erroring downstream.
        // Only this service writes the marker.
        return (
            is_array($state)
            && is_int($state['actor_id'] ?? null)
            && is_string($state['started_at'] ?? null)
        ) ? $state : null;
    }

    /**
     * Sign the session in as the given user.
     *
     * Drops auth state that must not cross identities: password confirmations ('auth') and the per-guard password-hash pins,
     * Sanctum's AuthenticateSession compares the pinned hash against the resolved user and would flush the swapped session as a takeover.
     * Forgetting the pin lets it re-pin the new identity on the next request.
     *
     * The swap is invisible to the authentication log (SWAP_ATTRIBUTE): nobody signed in, an already-authenticated
     * session changed hands - the audit trail is the record of that.
     */
    private function swapTo(User $user, Request $request): void
    {
        $request->attributes->set(self::SWAP_ATTRIBUTE, true);

        $request->session()->forget([
            'auth',
            ...array_map(
                static fn(string $guard): string => 'password_hash_'.$guard,
                Arr::wrap(config('sanctum.guard', 'web'))
            ),
        ]);

        Auth::guard('web')->login($user);

        $request->session()->regenerate();
    }
}
