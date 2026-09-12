<?php

namespace App\Services\Auth;

use App\Enums\TwoFactorState;
use App\Models\User;
use App\Support\Auth\TwoFactorEnrollment;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor enrollment and verification (RFC 6238 via google2fa).
 *
 * Enrollment is two-step: startEnrollment() mints an unconfirmed secret, and only confirmEnrollment() - proof the
 * user's authenticator produces valid codes - activates the factor, so a mistyped setup cannot lock anyone out.
 *
 * Every code works once. A verified TOTP code persists its time step and codes at or before it never verify again;
 * recovery codes exist in plaintext only in the response that mints them, are bcrypt hashes at rest and are redeemed by removal.
 * Consumption is the decision, not a write after it: every write is a conditional update pinned to the enrollment the
 * request verified against and its state, and a redemption removes its hash under a row lock - so two requests
 * carrying the same code cannot both succeed, and a factor disabled, replaced or confirmed in the meantime is never
 * touched on the strength of a stale snapshot.
 */
readonly class TwoFactorService
{
    private const int RECOVERY_CODE_COUNT = 8;

    /**
     * Lookalike characters (0/O, 1/I/L) are excluded so a recovery code read off a printout types back in unambiguously.
     */
    private const string RECOVERY_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(protected Google2FA $engine)
    {
    }

    /**
     * Mint a fresh unconfirmed secret and hand back what the authenticator setup screen needs.
     * Restarting while unconfirmed simply replaces the pending secret;
     * Returns null when the factor is already active - the user must disable it first - including when it became
     * active after this request loaded the user, so a stale start can never wipe a confirmed factor.
     */
    public function startEnrollment(User $user): ?TwoFactorEnrollment
    {
        if ($user->hasTwoFactorEnabled()) {
            return null;
        }

        $secret = $this->engine->generateSecretKey(32);

        $started = $this->consume($user, [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_verified_step' => null,
        ], static fn(Builder $query): Builder => $query->whereNull('two_factor_confirmed_at'));

        if (!$started) {
            return null;
        }

        $otpauthUrl = $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);

        return new TwoFactorEnrollment($secret, $otpauthUrl, $this->qrSvg($otpauthUrl));
    }

    /**
     * Activate the pending secret once the given code proves the user's authenticator works, and mint the recovery codes.
     * Returns the plaintext codes - the only time they exist outside their hashes - or null when there is no pending secret or the code is wrong.
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

        // Only the pending secret the code proved, and only while still unconfirmed: a restart or a concurrent confirm in between leaves nothing for this one to activate.
        $activated = $this->consume($user, [
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->hashed($codes),
            'two_factor_last_verified_step' => $step,
        ], $this->sameEnrollment($user, confirmed: false));

        return $activated ? $codes : null;
    }

    /**
     * Verify a TOTP code for an active enrollment, consuming its time step.
     *
     * The step is written only for the enrollment the code was verified against, still active, and only where the
     * stored step is older: of two requests carrying the same code the first consumes it, and a code for a factor
     * disabled or replaced in the meantime is refused.
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

        $sameEnrollment = $this->sameEnrollment($user, confirmed: true);

        return $this->consume(
            $user,
            ['two_factor_last_verified_step' => $step],
            static fn(Builder $query): Builder => $sameEnrollment($query)->where(
                static fn(Builder $query): Builder => $query
                    ->whereNull('two_factor_last_verified_step')
                    ->orWhere('two_factor_last_verified_step', '<', $step)
            ),
        );
    }

    /**
     * Redeem a recovery code by removing it: each code works exactly once, and the stored list is always the remaining ones.
     *
     * The bcrypt scan runs outside the lock, since it is the slow part; the removal re-reads the list under a row lock
     * and drops that exact hash, so concurrent redemptions of different codes each remove only their own, and a second
     * request carrying the same code finds it gone.
     */
    public function redeemRecoveryCode(User $user, string $code): bool
    {
        if (!$user->hasTwoFactorEnabled()) {
            return false;
        }

        $matched = null;

        foreach ($user->two_factor_recovery_codes ?? [] as $hash) {
            if (Hash::check($code, $hash)) {
                $matched = $hash;

                break;
            }
        }

        if ($matched === null) {
            return false;
        }

        return DB::transaction(function () use ($user, $matched): bool {
            /** @var User|null $fresh */
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return false;
            }

            $remaining = $fresh->two_factor_recovery_codes ?? [];
            $index = array_search($matched, $remaining, true);

            if ($index === false) {
                return false;
            }

            unset($remaining[$index]);
            $remaining = array_values($remaining);

            $fresh->forceFill(['two_factor_recovery_codes' => $remaining])->save();
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->syncOriginal();

            return true;
        });
    }

    /**
     * The constraint that the enrollment this model loaded is still the stored one, in the given confirmation state.
     *
     * The secret is compared as the ciphertext the model read - encryption is randomized, so a secret replaced by a
     * restart or cleared by a disable never matches, even if it re-encrypted the same key.
     *
     * @return Closure(Builder<User>): Builder<User>
     */
    private function sameEnrollment(User $user, bool $confirmed): Closure
    {
        $storedSecret = $user->getRawOriginal('two_factor_secret');

        return static fn(Builder $query): Builder => $query
            ->where('two_factor_secret', $storedSecret)
            ->when($confirmed,
                static fn(Builder $query): Builder => $query->whereNotNull('two_factor_confirmed_at'),
                static fn(Builder $query): Builder => $query->whereNull('two_factor_confirmed_at'),
            );
    }

    /**
     * Write the attributes only where the row still satisfies the constraint, as one conditional update.
     *
     * The attributes go through the model first so the casts apply (JSON list, encrypted secret), then the dirty
     * raw values are written by the query. One affected row means this request consumed the state and the model is
     * synced to it; zero means another request got there first and the model is left as it was.
     *
     * @param  array<string, mixed>  $attributes
     * @param  Closure(Builder<User>): Builder<User>  $stillHolds
     */
    private function consume(User $user, array $attributes, Closure $stillHolds): bool
    {
        $user->forceFill($attributes)->setUpdatedAt($user->freshTimestamp());

        $affected = $stillHolds($user->newQuery()->whereKey($user->getKey()))->update($user->getDirty());

        if ($affected === 0) {
            $user->discardChanges();

            return false;
        }

        $user->syncOriginal();

        return true;
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

        $replaced = $this->consume(
            $user,
            ['two_factor_recovery_codes' => $this->hashed($codes)],
            $this->sameEnrollment($user, confirmed: true),
        );

        return $replaced ? $codes : null;
    }

    /**
     * Clear every trace of the enrollment, pending or active, and report what was there.
     *
     * Every column is written, whatever this model believed: a save of the dirty attributes alone would let a stale
     * snapshot clear the secret and leave a confirmation and recovery set another request stored meanwhile.
     * The state is read under a row lock so the caller's audit and mail follow what was actually cleared.
     */
    public function disable(User $user): TwoFactorState
    {
        $cleared = [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_verified_step' => null,
        ];

        return DB::transaction(function () use ($user, $cleared): TwoFactorState {
            $state = TwoFactorState::of(User::query()->whereKey($user->getKey())->lockForUpdate()->first());

            $user->newQuery()->whereKey($user->getKey())->update($cleared);
            $user->forceFill($cleared)->syncOriginal();

            return $state;
        });
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
