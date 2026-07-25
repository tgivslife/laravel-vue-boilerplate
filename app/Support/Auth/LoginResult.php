<?php

namespace App\Support\Auth;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Represents the outcome of an authentication attempt.
 *
 * Produced by auth services and consumed by LoginResponse to build the HTTP response.
 * Use the static factory methods to construct instances - the constructor is private.
 */
readonly class LoginResult
{
    private function __construct(
        public LoginStatus $status,
        public ?User $user = null,
        public mixed $extra = null,
    ) {
    }

    /**
     * Authentication succeeded.
     *
     * @param  mixed|null  $extra  Optional extra data (e.g. ['token' => ..., 'expires_at' => ...] for API auth).
     */
    public static function success(User $user, mixed $extra = null): self
    {
        return new self(LoginStatus::Success, $user, $extra);
    }

    /**
     * The provided credentials did not match any user.
     */
    public static function invalidCredentials(): self
    {
        return new self(LoginStatus::InvalidCredentials);
    }

    /**
     * The user is already authenticated and cannot log in again.
     */
    public static function alreadyAuthenticated(): self
    {
        return new self(LoginStatus::AlreadyAuthenticated);
    }

    /**
     * The user exists but has not verified their email address.
     */
    public static function emailNotVerified(): self
    {
        return new self(LoginStatus::EmailNotVerified);
    }

    /**
     * The user's account has been deactivated by an administrator.
     */
    public static function accountDeactivated(): self
    {
        return new self(LoginStatus::AccountDeactivated);
    }

    /**
     * The user's account is temporarily locked due to too many failed attempts.
     *
     * @param  CarbonInterface  $until  The datetime when the lock expires.
     */
    public static function accountLocked(CarbonInterface $until): self
    {
        return new self(LoginStatus::AccountLocked, extra: $until);
    }

    /**
     * Authentication requires a second factor before access is granted.
     */
    public static function twoFactorRequired(): self
    {
        return new self(LoginStatus::TwoFactorRequired);
    }

    /**
     * The submitted two-factor code did not verify.
     *
     * One status for a wrong TOTP code, a replayed one and an unknown recovery code: distinguishing them
     * would tell a guesser which failure mode it hit.
     */
    public static function invalidTwoFactorCode(): self
    {
        return new self(LoginStatus::InvalidTwoFactorCode);
    }

    /**
     * No two-factor challenge is pending: it was never started, timed out, or its account can no longer complete it.
     * The user must sign in again.
     */
    public static function twoFactorChallengeExpired(): self
    {
        return new self(LoginStatus::TwoFactorChallengeExpired);
    }

    /**
     * The request has no session store attached (e.g. a non-frontend client
     * missing the Origin/Referer header expected by Sanctum's stateful guard).
     */
    public static function sessionUnavailable(): self
    {
        return new self(LoginStatus::SessionUnavailable);
    }

    /**
     * The presented magic-link token was unknown, expired, or already used.
     *
     * One status for all three cases on purpose: distinguishing them would
     * tell a caller whether a guessed token ever existed.
     */
    public static function invalidMagicLink(): self
    {
        return new self(LoginStatus::InvalidMagicLink);
    }

    /**
     * Check whether this result has a specific status.
     */
    public function is(LoginStatus $status): bool
    {
        return $this->status === $status;
    }
}
