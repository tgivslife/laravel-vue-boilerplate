<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Sends password-reset links and performs the reset itself.
 *
 * Built on the framework's password broker, which owns token creation,
 * hashing, expiry and single-use semantics (config/auth.php `passwords.users`).
 *
 * Send side: enumeration-resistant like MagicLinkService - {@see sendResetLink()}
 * returns void no matter what (unknown email, deactivated or banned account,
 * feature disabled), and the mail is queued, so a caller can never observe
 * whether the email belonged to a user. The broker's own resend throttle
 * (`passwords.users.throttle`) dedupes repeat sends server-side.
 *
 * Reset side: every failure (unknown email, wrong or expired token, account
 * that may not authenticate) collapses into one indistinguishable "invalid"
 * outcome, so the endpoint never becomes an account-state oracle.
 */
readonly class PasswordResetService
{
    public function __construct(protected SessionRegistry $sessionRegistry)
    {
    }

    /**
     * Email a password-reset link, if the address belongs to a usable user.
     *
     * The account-state gate runs here rather than in the broker so a
     * deactivated or banned account never receives a link it cannot use.
     */
    public function sendResetLink(string $email): void
    {
        if (!(bool) config('security.password_reset.enabled', true)) {
            return;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || !$user->canAuthenticate()) {
            return;
        }

        Password::sendResetLink(['email' => $email]);
    }

    /**
     * Reset the password for the credentials' user.
     *
     * A reset is the account-recovery path, so no credential that predates
     * it may survive: every session is destroyed, the remember token is
     * rotated, and every personal access token is revoked - an attacker
     * who held the account must not keep API access through a token they
     * minted. (The routine settings password change deliberately spares
     * tokens; only recovery is this aggressive.) The PasswordReset event
     * is fired afterwards.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $credentials
     */
    public function reset(array $credentials): bool
    {
        if (!(bool) config('security.password_reset.enabled', true)) {
            return false;
        }

        $user = User::query()->where('email', $credentials['email'] ?? '')->first();

        if ($user !== null && !$user->canAuthenticate()) {
            return false;
        }

        $status = Password::reset($credentials, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                // A reset satisfies an admin-imposed forced reset too.
                'require_password_reset' => false,
            ])->setRememberToken(Str::random(60));

            $user->save();

            $this->sessionRegistry->destroyAll($user);
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset;
    }
}
