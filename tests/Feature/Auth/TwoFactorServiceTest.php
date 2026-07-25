<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $service;

    private Google2FA $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(TwoFactorService::class);
        $this->engine = $this->app->make(Google2FA::class);
    }

    public function test_enrollment_mints_an_unconfirmed_secret(): void
    {
        $user = $this->createUser();

        $enrollment = $this->service->startEnrollment($user);

        $this->assertSame($enrollment->secret, $user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $this->assertStringStartsWith('otpauth://totp/', $enrollment->otpauthUrl);
        $this->assertStringContainsString('secret='.$enrollment->secret, $enrollment->otpauthUrl);
        $this->assertStringContainsString('<svg', $enrollment->qrSvg);

        // The secret is encrypted at rest, never stored as the base32 key.
        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($enrollment->secret, $raw);
    }

    public function test_an_unconfirmed_enrollment_can_be_restarted(): void
    {
        $user = $this->createUser();

        $first = $this->service->startEnrollment($user);
        $second = $this->service->startEnrollment($user);

        $this->assertNotSame($first->secret, $second->secret);
        $this->assertSame($second->secret, $user->fresh()->two_factor_secret);
    }

    public function test_enrollment_cannot_restart_while_active(): void
    {
        [$user] = $this->enrolledUser();

        $this->assertNull($this->service->startEnrollment($user));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirmation_requires_a_valid_code(): void
    {
        $user = $this->createUser();
        $enrollment = $this->service->startEnrollment($user);

        $this->assertNull($this->service->confirmEnrollment($user, $this->wrongCode($enrollment->secret)));
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $codes = $this->service->confirmEnrollment($user, $this->engine->getCurrentOtp($enrollment->secret));

        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(
                '/^([2-9A-HJKMNP-Z]{4}-){2}[2-9A-HJKMNP-Z]{4}$/',
                $code,
            );
        }

        // Stored hashed: the plaintext never appears in the persisted list.
        foreach ($user->fresh()->two_factor_recovery_codes as $hash) {
            $this->assertNotContains($hash, $codes);
        }
    }

    public function test_confirmation_consumes_the_code_it_verified(): void
    {
        $user = $this->createUser();
        $enrollment = $this->service->startEnrollment($user);
        $code = $this->engine->getCurrentOtp($enrollment->secret);

        $this->service->confirmEnrollment($user, $code);

        $this->assertFalse($this->service->verifyTotp($user, $code));
    }

    public function test_a_totp_code_verifies_once_per_time_step(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $code = $this->engine->getCurrentOtp($secret);

        $this->assertTrue($this->service->verifyTotp($user, $code));
        $this->assertFalse($this->service->verifyTotp($user, $code));
    }

    public function test_verification_refuses_inactive_enrollments(): void
    {
        $user = $this->createUser();

        $this->assertFalse($this->service->verifyTotp($user, '123456'));
        $this->assertFalse($this->service->redeemRecoveryCode($user, 'AAAA-BBBB-CCCC'));
        $this->assertNull($this->service->regenerateRecoveryCodes($user));

        // A pending (unconfirmed) secret must not verify either: only confirmEnrollment() may accept codes before activation.
        $enrollment = $this->service->startEnrollment($user);
        $this->assertFalse($this->service->verifyTotp($user, $this->engine->getCurrentOtp($enrollment->secret)));
    }

    public function test_recovery_codes_redeem_exactly_once(): void
    {
        [$user, , $codes] = $this->enrolledUser();

        $this->assertTrue($this->service->redeemRecoveryCode($user, $codes[0]));
        $this->assertFalse($this->service->redeemRecoveryCode($user, $codes[0]));

        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
        $this->assertTrue($this->service->redeemRecoveryCode($user, $codes[1]));
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_set(): void
    {
        [$user, , $old] = $this->enrolledUser();

        $new = $this->service->regenerateRecoveryCodes($user);

        $this->assertCount(8, $new);
        $this->assertFalse($this->service->redeemRecoveryCode($user, $old[0]));
        $this->assertTrue($this->service->redeemRecoveryCode($user, $new[0]));
    }

    public function test_disable_clears_all_two_factor_state(): void
    {
        [$user] = $this->enrolledUser();

        $this->service->disable($user);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_verified_step);
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    /**
     * A user with a confirmed enrollment, ready to verify codes.
     *
     * Confirmation consumes the current time step, and tests cannot wait out a 30-second window,
     * so the replay guard is rewound to let the current code verify again.
     *
     * @return array{User, string, list<string>}
     */
    private function enrolledUser(): array
    {
        $user = $this->createUser();

        $enrollment = $this->service->startEnrollment($user);
        $codes = $this->service->confirmEnrollment($user, $this->engine->getCurrentOtp($enrollment->secret));

        $user->forceFill(['two_factor_last_verified_step' => $user->two_factor_last_verified_step - 2])->save();

        return [$user, $enrollment->secret, $codes];
    }

    /**
     * A six-digit code guaranteed not to be the current OTP.
     */
    private function wrongCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret) === '000000' ? '111111' : '000000';
    }
}
