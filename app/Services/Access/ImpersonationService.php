<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Signing in as another account: a same-session identity swap, bracketed by audit entries.
 *
 * The borrowed session carries a marker (actor id, start time, the actor's credential pin): the resource layer reads it
 * for the banner, EnsureNotImpersonating to keep access administration, token and credential surfaces closed.
 * Both directions of the swap regenerate the session id; the marker is what survives, and the target never receives
 * a remember token, so nothing outlives the borrowed session itself.
 *
 * The session remains the actor's: the registry files it under them, so their own revocations destroy it, and the pin is
 * checked on every request and on stop, so it cannot outlive the credentials that opened it. Sanctum's pin covers the target.
 *
 * Tier rule (strict): top-tier targets (AccessScope::isTopTier()) may only be impersonated by super admins.
 * Scope dimensions veto out-of-reach targets like every other per-user action.
 */
readonly class ImpersonationService
{
    /**
     * Request attribute marking an in-flight identity swap, so the login/logout listeners treat the guard events as bookkeeping, not the owner signing in.
     */
    public const string SWAP_ATTRIBUTE = 'impersonation.swap';

    private const string SESSION_KEY = 'impersonation';

    public function __construct(private AccessScope $access, private AccessAuditor $auditor)
    {
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

        // UserPolicy::impersonate - one composition point for capability + record reach.
        // The stricter tier rule below is rank, not reach, and deliberately stays here.
        if ($actor->cannot('impersonate', $target)) {
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
            'actor_password_hash' => $this->credentialPin($actor),
        ]);
    }

    /**
     * End the swap and restore the actor.
     *
     * The actor is re-resolved strictly: an admin deactivated, banned or deleted mid-impersonation, or whose password
     * changed since the swap, is not restored - the session is destroyed outright, leaving no one signed in.
     */
    public function stop(Request $request): ?User
    {
        $state = $this->state($request);

        if ($state === null) {
            throw ValidationException::withMessages([
                'user' => __('api.access.impersonation_not_active'),
            ]);
        }

        $actor = $this->actor($state);

        $this->auditEnd($actor, $request->user());

        $request->session()->forget(self::SESSION_KEY);

        if (!$this->vouches($actor, $state)) {
            $this->destroySession($request);

            return null;
        }

        $this->swapTo($actor, $request);

        return $actor;
    }

    /**
     * Tear down a borrowed session whose actor was retired or re-credentialed since the swap, ended entry first.
     * Run on every authenticated request. Returns true when torn down, false when not impersonating or the actor still vouches.
     */
    public function cutOffUnvouchedActor(Request $request): bool
    {
        $state = $this->state($request);

        if ($state === null) {
            return false;
        }

        $actor = $this->actor($state);

        if ($this->vouches($actor, $state)) {
            return false;
        }

        $this->tearDown($actor, $request);

        return true;
    }

    /**
     * Write the ended entry and drop the marker for a borrowed session being torn down outside this service
     * (Sanctum's password pin, after the target's own password changed). The caller destroys the session.
     */
    public function closeWindow(Request $request, ?Authenticatable $target): void
    {
        $state = $this->state($request);

        if ($state === null) {
            return;
        }

        $this->auditEnd($this->actor($state), $target);

        $request->session()->forget(self::SESSION_KEY);
    }

    /**
     * Tear down a borrowed session on a full sign-out (logout request, or the target's ineligibility cutoff), ended entry first.
     * Returns false when the session is not impersonating: the caller performs its ordinary logout.
     */
    public function abandon(Request $request): bool
    {
        $state = $this->state($request);

        if ($state === null) {
            return false;
        }

        $this->tearDown($this->actor($state), $request);

        return true;
    }

    /**
     * Close the audit window, drop the marker and destroy the borrowed session, in that order.
     */
    private function tearDown(?User $actor, Request $request): void
    {
        $this->auditEnd($actor, $request->user());

        $request->session()->forget(self::SESSION_KEY);
        $this->destroySession($request);
    }

    /**
     * The marker's actor, tombstones included so the ended entry can still name them.
     *
     * @param  array{actor_id: int, started_at: string, actor_password_hash: string}  $state
     */
    private function actor(array $state): ?User
    {
        /** @var User|null $actor */
        $actor = User::withTrashed()->find($state['actor_id']);

        return $actor;
    }

    /**
     * Whether the actor may still be restored: alive, eligible, and holding the credentials the swap was opened with.
     *
     * @param  array{actor_id: int, started_at: string, actor_password_hash: string}  $state
     */
    private function vouches(?User $actor, array $state): bool
    {
        return $actor !== null
            && !$actor->trashed()
            && $actor->canAuthenticate()
            && hash_equals($state['actor_password_hash'], $this->credentialPin($actor));
    }

    /**
     * The user's credential version, keyed the way Sanctum's AuthenticateSession pins the current identity,
     * so the session never holds a raw password hash. Any password change - self-service, forced, recovery - rotates it.
     *
     * @throws RuntimeException When the web guard is not a session guard.
     */
    private function credentialPin(User $user): string
    {
        $guard = Auth::guard('web');

        if (!$guard instanceof SessionGuard) {
            throw new RuntimeException('Impersonation requires the web guard to be a session guard.');
        }

        return $guard->hashPasswordForCookie((string) $user->getAuthPassword());
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
     * logoutCurrentDevice() rather than logout(): a full logout would cycle the target's remember token and sign them out
     * of their own devices, and the borrowed session never held a remember cookie anyway.
     * SWAP_ATTRIBUTE tells the logout listeners this teardown is bookkeeping, already audited.
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
     * @return array{actor_id: int, started_at: string, actor_password_hash: string}|null
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
            && is_string($state['actor_password_hash'] ?? null)
        ) ? $state : null;
    }

    /**
     * Sign the session in as the given user.
     *
     * Drops auth state that must not cross identities: password confirmations and the per-guard password-hash pins,
     * which Sanctum would otherwise read as a takeover and flush; it re-pins the new identity on the next request.
     * SWAP_ATTRIBUTE keeps the swap out of the authentication log - the audit trail is the record of it.
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
