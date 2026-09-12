<?php

namespace App\Services\Auth;

use App\Contracts\AuthServiceContract;
use App\Http\Payloads\Auth\LoginPayload;
use App\Services\Access\ImpersonationService;
use App\Support\Auth\LoginMethod;
use App\Support\Auth\LoginResult;
use App\Support\Auth\LoginStatus;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Base authentication service: the shared brute-force throttle and logout, around a strategy's {@see attemptLogin()}.
 *
 * The throttle counts failed attempts only (cleared on success), so legitimate users never accrue lockout pressure,
 * and runs after Form Request validation rather than as route middleware, so it never sees malformed input.
 * Logout tears down whichever credential actually authenticated the request - session, with its registry row
 * dropped first, or personal access token - rather than relying on the Origin/Referer detection that picks the service.
 */
abstract class AuthService implements AuthServiceContract
{
    public function __construct(protected readonly LoginRateLimiter $limiter)
    {
    }

    /**
     * Authenticate the given credentials, enforcing the failure lockout.
     *
     * Answers `accountLocked` without touching credentials once the email/IP pair has exceeded `security.lockout.max_attempts`;
     * otherwise runs {@see attemptLogin()}, then clears the counter on success or increments it on invalid credentials.
     */
    public function login(LoginPayload $loginPayload): LoginResult
    {
        // Declared up front so both the success and the Failed-event paths record how this attempt came in.
        LoginMethod::Password->declare();

        if (!(bool) config('security.lockout.enabled', true)) {
            return $this->attemptLogin($loginPayload);
        }

        if ($this->limiter->tooManyAttempts($loginPayload)) {
            event(new Lockout(request()));

            return LoginResult::accountLocked(now()->addSeconds($this->limiter->availableIn($loginPayload)));
        }

        $result = $this->attemptLogin($loginPayload);

        if ($result->is(LoginStatus::Success)) {
            $this->limiter->clear($loginPayload);
        } elseif ($result->is(LoginStatus::InvalidCredentials)) {
            $this->limiter->increment($loginPayload);
        }

        return $result;
    }

    /**
     * Attempt authentication the strategy's way (session or token); the lockout is already enforced.
     */
    abstract protected function attemptLogin(LoginPayload $loginPayload): LoginResult;

    /**
     * Revoke the active access token and/or terminate the session.
     *
     * A bearer-authenticated request loses its personal access token. A session is invalidated and its CSRF token
     * regenerated, its registry row dropped first, before anything local changes: a row left live would take a late
     * write of the session back as signed in, so a logout that cannot drop it fails outright, for the user to retry.
     * A borrowed session (admin impersonation) signs out through the impersonation teardown instead, which writes
     * the ended audit entry and leaves the target's remember token alone - a plain logout would cycle it and sign
     * the target out of their own remembered devices.
     *
     * @throws Throwable When the registry row cannot be dropped.
     */
    public function logout(Request $request): void
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        if (!$request->hasSession()) {
            return;
        }

        if (app(ImpersonationService::class)->abandon($request)) {
            return;
        }

        app(SessionRegistry::class)->forgetCurrent($request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
