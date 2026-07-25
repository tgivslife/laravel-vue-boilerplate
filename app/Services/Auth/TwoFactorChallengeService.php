<?php

namespace App\Services\Auth;

use App\Http\Payloads\Auth\LoginPayload;
use App\Models\User;
use App\Support\Auth\LoginMethod;
use App\Support\Auth\LoginResult;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;

/**
 * Parks a credential-verified login while its second factor is pending, and completes it when the challenge is answered.
 *
 * The pending state lives in the (still unauthenticated) session: user id, remember flag,
 * the door the credentials came through, and a deadline.
 * Nothing about it is secret - it only exists in a browser that already presented valid credentials,
 * but it grants nothing either, the session stays guest until the challenge verifies.
 *
 * Failed challenge codes feed the same email+IP lockout bucket as failed passwords (LoginRateLimiter),
 * fire the framework Failed event so the authentication log records the episode, and trip the same Lockout
 * notification once the threshold is crossed.
 */
readonly class TwoFactorChallengeService
{
    private const string SESSION_KEY = 'two_factor.pending';

    public function __construct(
        private TwoFactorService $twoFactor,
        private LoginRateLimiter $limiter,
    ) {
    }

    /**
     * Park a credential-verified login pending its second factor.
     *
     * The session id is regenerated so the pre-login session cannot be fixated while it carries the pending state.
     */
    public function stash(User $user, bool $remember): void
    {
        request()->session()->put(self::SESSION_KEY, [
            'id' => $user->getKey(),
            'remember' => $remember,
            'method' => LoginMethod::declared(),
            'expires_at' => now()->addMinutes($this->ttlMinutes())->getTimestamp(),
        ]);

        request()->session()->regenerate();
    }

    /**
     * Answer the pending challenge with a TOTP code or a recovery code.
     *
     * Every way the pending state can be unusable (absent, timed out, account gone or no longer allowed to authenticate,
     * factor disabled meanwhile) collapses into `twoFactorChallengeExpired`;
     * the fix is the same - sign in again - and the distinction would only describe the account's state
     * to whoever is holding the session.
     */
    public function challenge(?string $code, ?string $recoveryCode): LoginResult
    {
        if (!request()->hasSession()) {
            return LoginResult::sessionUnavailable();
        }

        if (Auth::guard('web')->check()) {
            return LoginResult::alreadyAuthenticated();
        }

        $pending = request()->session()->get(self::SESSION_KEY);

        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            request()->session()->forget(self::SESSION_KEY);

            return LoginResult::twoFactorChallengeExpired();
        }

        $user = User::query()->find($pending['id']);

        if ($user === null || !$user->canAuthenticate() || !$user->hasTwoFactorEnabled()) {
            request()->session()->forget(self::SESSION_KEY);

            return LoginResult::twoFactorChallengeExpired();
        }

        // Re-declare the original door so the auth-log listeners attribute
        // the challenge outcome to it, not to a method-less episode.
        $method = LoginMethod::tryFrom((string) $pending['method']) ?? LoginMethod::Password;
        $method->declare();

        $payload = $this->limiterPayload($user);
        $lockoutEnabled = (bool) config('security.lockout.enabled', true);

        if ($lockoutEnabled && $this->limiter->tooManyAttempts($payload)) {
            /*
             * The challenge request carries no email input, but the lockout listener resolves its recipient from one;
             * the pending user is who a password attempt would have named.
             */
            request()->merge(['email' => $user->email]);
            event(new Lockout(request()));

            return LoginResult::accountLocked(now()->addSeconds($this->limiter->availableIn($payload)));
        }

        $verified = $recoveryCode !== null
            ? $this->twoFactor->redeemRecoveryCode($user, $recoveryCode)
            : $this->twoFactor->verifyTotp($user, (string) $code);

        if (!$verified) {
            if ($lockoutEnabled) {
                $this->limiter->increment($payload);
            }

            event(new Failed('web', $user, []));

            return LoginResult::invalidTwoFactorCode();
        }

        request()->session()->forget(self::SESSION_KEY);
        $this->limiter->clear($payload);

        Auth::guard('web')->login($user, (bool) $pending['remember']);

        request()->session()->regenerate();

        return LoginResult::success($user);
    }

    /**
     * The same email+IP bucket password failures use, so guessing codes
     * and guessing passwords accrue one shared lockout pressure.
     */
    private function limiterPayload(User $user): LoginPayload
    {
        return new LoginPayload(
            email: $user->email,
            password: '',
            remember: false,
            ip: (string) request()->ip(),
        );
    }

    private function ttlMinutes(): int
    {
        return (int) config('security.two_factor.challenge_ttl_minutes', 5);
    }
}
