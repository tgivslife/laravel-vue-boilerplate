<?php

namespace App\Services\Auth;

use App\Http\Payloads\Auth\LoginPayload;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

/**
 * Failure-counting login rate limiter.
 *
 * Unlike the generic `throttle` middleware, this limiter counts only failed
 * authentication attempts: the caller increments on failure and clears on
 * success, so legitimate users never accrue lockout pressure. Attempts are
 * keyed per email + IP and read from the `security.lockout` configuration.
 */
readonly class LoginRateLimiter
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    /**
     * Get the number of failed attempts recorded for the credentials.
     */
    public function attempts(LoginPayload $payload): int
    {
        return (int) $this->limiter->attempts($this->throttleKey($payload));
    }

    /**
     * Determine whether the credentials have too many failed attempts.
     */
    public function tooManyAttempts(LoginPayload $payload): bool
    {
        return $this->limiter->tooManyAttempts(
            $this->throttleKey($payload),
            (int) config('security.lockout.max_attempts')
        );
    }

    /**
     * Record a failed attempt, locking for the configured duration.
     */
    public function increment(LoginPayload $payload): void
    {
        $this->limiter->hit(
            $this->throttleKey($payload),
            (int) config('security.lockout.duration_minutes') * 60
        );
    }

    /**
     * Number of seconds until another attempt is permitted.
     */
    public function availableIn(LoginPayload $payload): int
    {
        return (int) $this->limiter->availableIn($this->throttleKey($payload));
    }

    /**
     * Clear the recorded attempts for the credentials.
     */
    public function clear(LoginPayload $payload): void
    {
        $this->limiter->clear($this->throttleKey($payload));
    }

    /**
     * Build the per-credentials throttle key from the email and IP.
     */
    protected function throttleKey(LoginPayload $payload): string
    {
        return Str::transliterate(Str::lower($payload->email).'|'.$payload->ip);
    }
}
