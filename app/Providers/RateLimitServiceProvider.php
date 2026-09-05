<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * A request field coerced to a string for use as a rate-limit key, before validation has run.
     *
     * The limiters key on `email` / `token` straight from the unvalidated request, so the field can be any JSON
     * shape a caller sends. A bare `(string)` cast throws "Array to string conversion" on an array value, and with
     * warnings promoted to exceptions that surfaces as a 500 from inside the throttle middleware - before the form
     * request could answer the malformed shape with a 422. Non-scalars collapse to an empty key instead; scalars
     * keep the exact string the cast produced.
     */
    private static function stringInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', static function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        /*
         * IP-volume ceiling on the credential doors, complementing the per-credential failure lockout (LoginRateLimiter):
         * counts every request, so password spraying across many emails can't slip under the per-email bucket.
         */
        RateLimiter::for('login', static function (Request $request) {
            $max = (int) config('security.login.request_limit.max_attempts', 20);
            $decay = (int) config('security.login.request_limit.decay_minutes', 1);

            return Limit::perMinutes($decay, $max)->by('login:ip:'.$request->ip());
        });

        /*
         * Counts every request, not just failures: sending email is the cost, so volume itself is the abuse vector.
         */
        RateLimiter::for('magic-link-request', static function (Request $request) {
            $email = mb_strtolower(trim(self::stringInput($request, 'email')));
            $max = (int) config('security.magic_link.request_limit.max_attempts', 5);
            $decay = (int) config('security.magic_link.request_limit.decay_minutes', 15);

            return [
                Limit::perMinutes($decay, $max)->by('magic-link:request:email:'.sha1($email)),
                Limit::perMinutes($decay, $max)->by('magic-link:request:ip:'.$request->ip()),
            ];
        });

        /*
         * Same shape as magic-link-request: the endpoint sends email, so
         * volume itself is the abuse vector, counted per target address and per caller IP.
         */
        RateLimiter::for('password-reset-request', static function (Request $request) {
            $email = mb_strtolower(trim(self::stringInput($request, 'email')));
            $max = (int) config('security.password_reset.request_limit.max_attempts', 5);
            $decay = (int) config('security.password_reset.request_limit.decay_minutes', 15);

            return [
                Limit::perMinutes($decay, $max)->by('password-reset:request:email:'.sha1($email)),
                Limit::perMinutes($decay, $max)->by('password-reset:request:ip:'.$request->ip()),
            ];
        });

        /*
         * Backstop against reset-token guessing; the real bound is token
         * entropy plus the broker's expiry.
         */
        RateLimiter::for('password-reset-attempt', static function (Request $request) {
            $max = (int) config('security.password_reset.attempt_limit.max_attempts', 10);
            $decay = (int) config('security.password_reset.attempt_limit.decay_minutes', 1);

            return Limit::perMinutes($decay, $max)->by('password-reset:attempt:ip:'.$request->ip());
        });

        /*
         * Browser-facing OIDC redirect/callback endpoints; per IP, generous enough for normal sign-ins
         * while blunting redirect spam toward the identity providers.
         */
        RateLimiter::for('oidc', static function (Request $request) {
            return Limit::perMinute(20)->by('oidc:'.$request->ip());
        });

        /*
         * Endpoints that verify the account password before a destructive action (account deletion, signing out other sessions);
         * capped per user so a hijacked session cannot brute-force the password through them.
         */
        RateLimiter::for('password-confirm', static function (Request $request) {
            $max = (int) config('security.password_confirm_limit.max_attempts', 5);
            $decay = (int) config('security.password_confirm_limit.decay_minutes', 1);

            return Limit::perMinutes($decay, $max)
                ->by('password-confirm:user:'.($request->user()?->id ?: $request->ip()));
        });

        /*
         * Token creation is password-confirmed, but still capped per user so
         * a hijacked session cannot mint long-lived credentials in bulk.
         */
        RateLimiter::for('pat-create', static function (Request $request) {
            $max = (int) config('security.personal_access_tokens.create_limit.max_attempts', 10);
            $decay = (int) config('security.personal_access_tokens.create_limit.decay_minutes', 60);

            return Limit::perMinutes($decay, $max)->by('pat:create:user:'.($request->user()?->id ?: $request->ip()));
        });

        /*
       * Backstop against token guessing; the real bound is token entropy.
       * The token is hashed so the raw secret never becomes a cache key.
       */
        RateLimiter::for('magic-link-consume', static function (Request $request) {
            $max = (int) config('security.magic_link.consume_limit.max_attempts', 10);
            $decay = (int) config('security.magic_link.consume_limit.decay_minutes', 1);

            return [
                Limit::perMinutes($decay, $max)->by('magic-link:consume:ip:'.$request->ip()),
                Limit::perMinutes($decay, $max)->by('magic-link:consume:token:'.hash('sha256',
                        self::stringInput($request, 'token'))),
            ];
        });
    }
}
