<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

/**
 * Sends password-reset links and performs the reset itself, on the framework's password broker, which owns token
 * creation, hashing, expiry and single-use semantics (config/auth.php `passwords.users`).
 *
 * Send side: enumeration-resistant like MagicLinkService. {@see sendResetLink()} returns void whatever it found,
 * the mail is queued and resolves the requesting device only when it renders, and the broker's resend throttle
 * (`passwords.users.throttle`) dedupes repeat sends server-side. Reset side: every failure - unknown email, wrong
 * or expired token, account that may not authenticate - collapses into one indistinguishable "invalid" outcome.
 *
 * Both sides run their whole decision inside a timebox of the service's own, so the duration is the floor whichever
 * branch ran: the broker's box covers only the branches that reach it, and an early return ahead of it answered in
 * under a millisecond against the broker's two hundred. The floor is a measured starting point above the slowest
 * branch's work (a token hash at production bcrypt cost, plus the broker's box), not a guarantee: work that
 * outgrows it under load shows through again.
 */
readonly class PasswordResetService
{
    /**
     * Minimum duration of a send or reset decision, in microseconds.
     */
    protected int $floorMicroseconds;

    /**
     * @param  Timebox  $timebox  The service's own, never the broker's: that one returns early on a successful reset.
     * @param  int|null  $floorMicroseconds  The decision floor; `security.auth_decision_floor_ms` unless given.
     */
    public function __construct(
        protected SessionRegistry $sessionRegistry,
        protected Timebox $timebox = new Timebox,
        ?int $floorMicroseconds = null,
    ) {
        $this->floorMicroseconds = $floorMicroseconds
            ?? 1000 * max((int) config('security.auth_decision_floor_ms', 500), 0);
    }

    /**
     * Email a password-reset link, if the address belongs to a usable user: the account-state gate runs here rather
     * than in the broker, so a deactivated or banned account never receives a link it cannot use.
     */
    public function sendResetLink(string $email): void
    {
        if (!(bool) config('security.password_reset.enabled', true)) {
            return;
        }

        $this->timebox->call(static function () use ($email): void {
            $user = User::query()->where('email', $email)->first();

            if ($user === null || !$user->canAuthenticate()) {
                return;
            }

            Password::sendResetLink(['email' => $email]);
        }, $this->floorMicroseconds);
    }

    /**
     * Reset the password for the credentials' user.
     *
     * A reset is the account-recovery path, so no credential that predates it may survive: every session is destroyed,
     * the remember token rotated and every personal access token revoked - an attacker who held the
     * account must not keep API access through a token they minted.
     * The routine settings password change spares tokens; only recovery is this aggressive. The PasswordReset event fires afterwards.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $credentials
     * @throws Throwable
     */
    public function reset(array $credentials): bool
    {
        if (!(bool) config('security.password_reset.enabled', true)) {
            return false;
        }

        return (bool) $this->timebox->call(function () use ($credentials): bool {
            $user = User::query()->where('email', $credentials['email'] ?? '')->first();

            if ($user !== null && !$user->canAuthenticate()) {
                return false;
            }

            $status = Password::reset($credentials, function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    // A reset satisfies an admin-imposed forced reset too.
                    'require_password_reset' => false,
                ])->setRememberToken(Str::random(60));

                $user->save();

                $this->sessionRegistry->destroyAll($user);
                $user->tokens()->delete();

                event(new PasswordReset($user));
            });

            return $status === Password::PasswordReset;
        }, $this->floorMicroseconds);
    }
}
