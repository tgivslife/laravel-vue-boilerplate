<?php

namespace App\Services\Auth;

use App\Http\Payloads\Auth\LoginPayload;
use App\Models\User;
use App\Support\Auth\LoginResult;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Auth;

/**
 * Session-based login strategy for stateful (SPA/browser) clients: no token
 * is issued, the session cookie carries authentication.
 * Logout and throttling are inherited from the base AuthService.
 */
class StatefulAuthService extends AuthService
{
    public function __construct(
        LoginRateLimiter $limiter,
        private readonly TwoFactorChallengeService $challenges,
    ) {
        parent::__construct($limiter);
    }

    /**
     * Credentials are checked with validate() rather than attempt(): the account-state and two-factor gates
     * run between "password correct" and "session established", and nothing may fire the framework Login event
     * (authentication log, new-device mail) for a login that never completes.
     * validate() fires no Failed event either, so the failure paths fire it explicitly for the auth-log listener.
     * On success the session is regenerated to prevent fixation.
     */
    protected function attemptLogin(LoginPayload $loginPayload): LoginResult
    {
        if (!request()->hasSession()) {
            return LoginResult::sessionUnavailable();
        }

        // Addressed explicitly: auth:sanctum on other routes switches the
        // manager's default guard via shouldUse(), so the default guard
        // depends on which middleware ran earlier in the process.
        $guard = Auth::guard('web');

        if ($guard->check()) {
            return LoginResult::alreadyAuthenticated();
        }

        $credentials = [
            'email' => $loginPayload->email,
            'password' => $loginPayload->password,
        ];

        if (!$guard->validate($credentials)) {
            event(new Failed('web', $guard->getLastAttempted(), $credentials));

            return LoginResult::invalidCredentials();
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        // Surfaced only after the credentials verified, so the deactivated outcome never becomes an account-probing oracle;
        // the Failed event keeps the attempt visible in the authentication log.
        if (!$user->canAuthenticate()) {
            event(new Failed('web', $user, $credentials));

            return LoginResult::accountDeactivated();
        }

        if ((bool) config('security.two_factor.enabled', true) && $user->hasTwoFactorEnabled()) {
            $this->challenges->stash($user, $loginPayload->remember);

            return LoginResult::twoFactorRequired();
        }

        $guard->login($user, $loginPayload->remember);

        request()->session()->regenerate();

        return LoginResult::success($user);
    }
}
