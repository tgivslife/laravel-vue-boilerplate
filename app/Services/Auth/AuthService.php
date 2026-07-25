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

/**
 * Base authentication service with failure-counting throttling and a
 * credential-driven logout.
 *
 * Login strategies implement {@see attemptLogin()}; the brute-force throttle and logout are shared.
 * Throttling counts only failed attempts (incremented on failure, cleared on success),
 * so legitimate users never accrue lockout pressure - and because it runs after Form Request validation rather than as
 * route middleware, it can never see malformed input.
 * Logout tears down whichever credential actually authenticated the request, rather than
 * relying on the Origin/Referer detection used to pick the service.
 */
abstract class AuthService implements AuthServiceContract
{
    public function __construct(protected readonly LoginRateLimiter $limiter)
    {
    }

    /**
     * Authenticate the given credentials, enforcing the failure lockout.
     *
     * Returns `accountLocked` before touching credentials once the email/IP
     * pair has exceeded `security.lockout.max_attempts`. Otherwise delegates to
     * the strategy-specific {@see attemptLogin()}, then clears the counter on
     * success or increments it on invalid credentials.
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
     * Attempt authentication with a specific strategy (session or token).
     */
    abstract protected function attemptLogin(LoginPayload $loginPayload): LoginResult;

    /**
     * Revoke the active access token and/or terminate the session.
     *
     * Deletes the personal access token when the request was authenticated
     * with a bearer token. When a session is present, it is invalidated and
     * the CSRF token regenerated to prevent fixation on the new session.
     *
     * A borrowed session (admin impersonation) signs out through the
     * impersonation teardown instead: the audit trail gets its ended entry,
     * and the target's remember token survives - a plain logout would cycle
     * it, silently signing the target out of their own remembered devices.
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

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
