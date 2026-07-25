<?php

namespace Tests\Feature\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use App\Services\Auth\MagicLinkTokenHasher;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $twoFactor;

    private Google2FA $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // The lockout limiter keys by email + IP, which is identical for every test in this file;
        // without a flush the counters bleed across tests.
        $this->app['cache']->flush();

        $this->twoFactor = $this->app->make(TwoFactorService::class);
        $this->engine = $this->app->make(Google2FA::class);
    }

    public function test_login_with_an_enrolled_account_parks_a_challenge_instead_of_authenticating(): void
    {
        [$user] = $this->enrolledUser();

        $response = $this->login($user);

        $response->assertOk();
        $response->assertJsonPath('data.two_factor_required', true);
        $response->assertJsonMissingPath('data.email');
        $this->assertGuest();
    }

    public function test_a_valid_totp_code_completes_the_login(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        $response = $this->challenge(['code' => $this->engine->getCurrentOtp($secret)]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_recovery_code_completes_the_login_and_burns(): void
    {
        [$user, , $codes] = $this->enrolledUser();
        $this->login($user);

        $this->challenge(['recovery_code' => $codes[0]])->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_the_remember_choice_survives_the_challenge(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user, remember: true);

        $this->challenge(['code' => $this->engine->getCurrentOtp($secret)])->assertOk();

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_a_wrong_code_is_refused_and_logged(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        $response = $this->challenge(['code' => $this->wrongCode($secret)]);

        $response->assertStatus(401);
        $response->assertJsonPath('title', __('api.auth.titles.invalid_two_factor_code'));
        $this->assertGuest();

        $failed = $user->authentications()->where('login_successful', false)->get();
        $this->assertCount(1, $failed);
        $this->assertSame('password', $failed->first()->login_method);
    }

    public function test_code_guessing_trips_the_login_lockout(): void
    {
        Notification::fake();

        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        foreach (range(1, (int) config('security.lockout.max_attempts')) as $attempt) {
            $this->challenge(['code' => $this->wrongCode($secret)])->assertStatus(401);
        }

        // Even the right code is refused while locked out.
        $this->challenge(['code' => $this->engine->getCurrentOtp($secret)])->assertStatus(423);
        $this->assertGuest();

        Notification::assertSentTo($user, AccountLockedNotification::class);
    }

    public function test_a_challenge_cannot_be_answered_without_a_pending_login(): void
    {
        $this->enrolledUser();

        $this->challenge(['code' => '123456'])->assertStatus(410);
    }

    public function test_the_pending_challenge_expires(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        $this->travel(6)->minutes();

        $this->challenge(['code' => $this->engine->getCurrentOtp($secret)])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_complete_a_pending_challenge(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        $user->forceFill(['is_active' => false])->save();

        $this->challenge(['code' => $this->engine->getCurrentOtp($secret)])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_a_completed_challenge_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);
        $this->challenge(['code' => $this->engine->getCurrentOtp($secret)])->assertOk();

        $this->challenge(['code' => '123456'])->assertStatus(409);
    }

    public function test_disabling_the_feature_bypasses_the_challenge(): void
    {
        config(['security.two_factor.enabled' => false]);
        [$user] = $this->enrolledUser();

        $this->challenge(['code' => '123456'])->assertStatus(404);

        $this->login($user)->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_magic_link_consumption_parks_a_challenge_for_enrolled_accounts(): void
    {
        [$user] = $this->enrolledUser();
        $token = $this->magicToken($user);

        $response = $this->consumeMagicLink($token);

        $response->assertOk();
        $response->assertJsonPath('data.two_factor_required', true);
        $this->assertGuest();

        // The link is spent even though the challenge was never answered:
        // an abandoned challenge must not leave a live link behind.
        $this->consumeMagicLink($token)->assertStatus(401);
    }

    public function test_a_magic_link_challenge_completes_with_the_original_method_logged(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->consumeMagicLink($this->magicToken($user));

        $this->challenge(['code' => $this->engine->getCurrentOtp($secret)])->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('magic_link', $user->authentications()->latest('id')->first()->login_method);
    }

    public function test_the_challenge_requires_exactly_one_code_field(): void
    {
        [$user] = $this->enrolledUser();
        $this->login($user);

        $this->challenge([])->assertStatus(422);
        $this->challenge(['code' => '123456', 'recovery_code' => 'AAAA-BBBB-CCCC'])->assertStatus(422);
    }

    private function login(User $user, bool $remember = false): TestResponse
    {
        return $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
                'remember' => $remember,
            ]);
    }

    private function challenge(array $payload): TestResponse
    {
        return $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/two-factor/challenge', $payload);
    }

    private function consumeMagicLink(string $token): TestResponse
    {
        config(['security.magic_link.enabled' => true]);

        return $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/magic-link/consume', ['token' => $token]);
    }

    /**
     * Mint a live magic-link token directly (the request/notification round
     * trip is MagicLinkTest's concern) and return its plaintext.
     */
    private function magicToken(User $user): string
    {
        $plaintext = 'two-factor-magic-token';

        MagicLinkToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => $this->app->make(MagicLinkTokenHasher::class)->hash($plaintext),
            'expires_at' => now()->addMinutes(15),
        ]);

        return $plaintext;
    }

    /**
     * A user with a confirmed enrollment, ready to answer challenges.
     *
     * Confirmation consumes the current time step, and tests cannot wait out a 30-second window,
     * so the replay guard is rewound to let the current code verify again.
     *
     * @return array{User, string, list<string>}
     */
    private function enrolledUser(): array
    {
        $user = $this->createUser();

        $enrollment = $this->twoFactor->startEnrollment($user);
        $codes = $this->twoFactor->confirmEnrollment($user, $this->engine->getCurrentOtp($enrollment->secret));

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
