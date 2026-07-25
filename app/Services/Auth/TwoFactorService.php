<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\TwoFactorEnrollment;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor enrollment and verification (RFC 6238 via google2fa).
 *
 * Enrollment is deliberately two-step: {@see startEnrollment()} mints an unconfirmed secret, and only
 * {@see confirmEnrollment()} - proof that the user's authenticator produces valid codes - activates the factor, so
 * nobody can lock themselves out with a mistyped setup.
 *
 * Recovery codes exist in plaintext only in the response that mints them;
 * at rest each is a bcrypt hash, redeemed by removal.
 * A verified TOTP code is single-use: the accepted time step is persisted and codes at or before
 * it never verify again, so an intercepted code cannot be replayed.
 */
readonly class TwoFactorService
{
    private const int RECOVERY_CODE_COUNT = 8;

    /**
     * Lookalike characters (0/O, 1/I/L) are excluded so a recovery code
     * read off a printout types back in unambiguously.
     */
    private const string RECOVERY_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(protected Google2FA $engine)
    {
    }

    /**
     * Mint a fresh unconfirmed secret and hand back what the authenticator setup screen needs.
     * Restarting while unconfirmed simply replaces the pending secret;
     * Returns null when the factor is already active - the user must disable it first.
     */
    public function startEnrollment(User $user): ?TwoFactorEnrollment
    {
        if ($user->hasTwoFactorEnabled()) {
            return null;
        }

        $secret = $this->engine->generateSecretKey(32);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_verified_step' => null,
        ])->save();

        $otpauthUrl = $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);

        return new TwoFactorEnrollment($secret, $otpauthUrl, $this->qrSvg($otpauthUrl));
    }

    /**
     * Activate the pending secret once the given code proves the user's authenticator works, and mint the recovery codes.
     * Returns the plaintext codes - the only time they exist outside their hashes - or null
     * when there is no pending secret or the code is wrong.
     * The verified step is recorded, so the confirmation code cannot be replayed as a login challenge.
     *
     * @return list<string>|null
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return null;
        }

        $step = $this->verifiedStep($user, $code);

        if ($step === null) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->hashed($codes),
            'two_factor_last_verified_step' => $step,
        ])->save();

        return $codes;
    }

    /**
     * Verify a TOTP code for an active enrollment, consuming its time step.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        if (!$user->hasTwoFactorEnabled()) {
            return false;
        }

        $step = $this->verifiedStep($user, $code);

        if ($step === null) {
            return false;
        }

        $user->forceFill(['two_factor_last_verified_step' => $step])->save();

        return true;
    }

    /**
     * Redeem a recovery code by removing it: each code works exactly once, and the stored list is always the remaining ones.
     */
    public function redeemRecoveryCode(User $user, string $code): bool
    {
        if (!$user->hasTwoFactorEnabled()) {
            return false;
        }

        $hashes = $user->two_factor_recovery_codes ?? [];

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Replace the recovery codes with a fresh set, invalidating every outstanding one.
     * Returns the plaintext codes, or null when the factor is not active.
     *
     * @return list<string>|null
     */
    public function regenerateRecoveryCodes(User $user): ?array
    {
        if (!$user->hasTwoFactorEnabled()) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $this->hashed($codes)])->save();

        return $codes;
    }

    /**
     * Clear every trace of the enrollment, pending or active.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_verified_step' => null,
        ])->save();
    }

    /**
     * The time step the code verifies at, or null when it is invalid or not newer than the last accepted step.
     * Passing 0 for a never-used secret keeps verifyKeyNewer() in its step-returning mode.
     */
    private function verifiedStep(User $user, string $code): ?int
    {
        $step = $this->engine->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_verified_step ?? 0,
            (int) config('security.two_factor.window', 1),
        );

        return $step === false ? null : (int) $step;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn(): string => implode('-', array_map(fn(): string => $this->randomGroup(4), range(1, 3))),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private function randomGroup(int $length): string
    {
        $max = strlen(self::RECOVERY_CODE_ALPHABET) - 1;

        return implode('', array_map(
            static fn(): string => self::RECOVERY_CODE_ALPHABET[random_int(0, $max)],
            range(1, $length),
        ));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashed(array $codes): array
    {
        return array_map(static fn(string $code): string => Hash::make($code), $codes);
    }

    /**
     * Inline SVG of the otp auth URI for the enrollment screen.
     */
    private function qrSvg(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(new RendererStyle(192, 0), new SvgImageBackEnd());

        return new Writer($renderer)->writeString($otpauthUrl);
    }
}
