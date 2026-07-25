<?php

namespace Tests\Feature\Auth;

use App\Contracts\CaptchaVerifier;
use App\Services\Auth\SiteVerifyCaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The anti-abuse hook on the public doors: off by default, and when enabled the configured
 * doors demand a captcha_token judged by the bound CaptchaVerifier.
 */
class CaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The door limiters key by IP, identical across tests in this file.
        $this->app['cache']->flush();

        config([
            'security.magic_link.enabled' => true,
            'security.magic_link.provision' => false,
        ]);

        Notification::fake();
    }

    /**
     * @param  list<string>  $doors
     */
    private function enableCaptcha(array $doors = ['login', 'magic_link', 'password_reset']): void
    {
        config([
            'security.captcha.enabled' => true,
            'security.captcha.doors' => $doors,
        ]);
    }

    private function bindVerifier(bool $passes): void
    {
        $this->app->instance(CaptchaVerifier::class, new class($passes) implements CaptchaVerifier
        {
            public function __construct(private readonly bool $passes)
            {
            }

            public function verify(string $token, ?string $ipAddress): bool
            {
                return $this->passes;
            }
        });
    }

    private function requestLink(string $email, array $extra = []): TestResponse
    {
        return $this
            ->withHeader('Referer', config('app.url'))
            ->postJson('/api/magic-link', ['email' => $email] + $extra);
    }

    public function test_a_disabled_gate_leaves_the_doors_open(): void
    {
        config(['security.captcha.enabled' => false]);
        $user = $this->createUser();

        $this->requestLink($user->email)->assertStatus(202);

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(200);
    }

    public function test_a_guarded_door_refuses_requests_without_a_token(): void
    {
        $this->enableCaptcha();
        $this->bindVerifier(true);
        $user = $this->createUser();

        $this->requestLink($user->email)
            ->assertStatus(422)
            ->assertJsonPath('title', __('api.auth.titles.captcha_failed'));

        Notification::assertNothingSent();
    }

    public function test_a_guarded_door_refuses_a_token_the_verifier_rejects(): void
    {
        $this->enableCaptcha();
        $this->bindVerifier(false);
        $user = $this->createUser();

        $this->requestLink($user->email, ['captcha_token' => 'bad-token'])
            ->assertStatus(422)
            ->assertJsonPath('title', __('api.auth.titles.captcha_failed'));
    }

    public function test_a_verified_token_opens_every_guarded_door(): void
    {
        $this->enableCaptcha();
        $this->bindVerifier(true);
        $user = $this->createUser();

        $this->requestLink($user->email, ['captcha_token' => 'good-token'])->assertStatus(202);
        Notification::assertCount(1);

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/password/forgot', ['email' => $user->email, 'captcha_token' => 'good-token'])
            ->assertStatus(202);

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
                'captcha_token' => 'good-token',
            ])
            ->assertStatus(200);
    }

    public function test_unlisted_doors_stay_open_without_a_token(): void
    {
        $this->enableCaptcha(['login']);
        $this->bindVerifier(true);
        $user = $this->createUser();

        $this->requestLink($user->email)->assertStatus(202);
    }

    public function test_enabled_but_unconfigured_fails_loudly(): void
    {
        // The default siteverify verifier has no secret configured: a deployment
        // that switched captcha on without finishing the job must hear about it.
        $this->enableCaptcha();
        config(['security.captcha.secret' => null]);
        $user = $this->createUser();

        $this->requestLink($user->email, ['captcha_token' => 'some-token'])->assertStatus(500);
    }

    public function test_the_siteverify_verifier_speaks_the_shared_protocol(): void
    {
        config([
            'security.captcha.verify_url' => 'https://captcha.example/siteverify',
            'security.captcha.secret' => 'secret-key',
        ]);

        Http::fake([
            'https://captcha.example/siteverify' => Http::sequence()
                ->push(['success' => true])
                ->push(['success' => false])
                ->pushStatus(503),
        ]);

        $this->assertTrue(app(SiteVerifyCaptchaVerifier::class)->verify('the-token', '203.0.113.9'));

        Http::assertSent(static function ($request): bool {
            return $request['secret'] === 'secret-key'
                && $request['response'] === 'the-token'
                && $request['remoteip'] === '203.0.113.9';
        });

        // A refusal and a transport failure both fail closed.
        $this->assertFalse(app(SiteVerifyCaptchaVerifier::class)->verify('the-token', null));
        $this->assertFalse(app(SiteVerifyCaptchaVerifier::class)->verify('the-token', null));
    }

    public function test_an_unreachable_siteverify_endpoint_fails_closed_and_logs_the_error(): void
    {
        // The client only ever sees the generic refusal, so the outage must be
        // diagnosable from the server log.
        config([
            'security.captcha.verify_url' => 'https://captcha.example/siteverify',
            'security.captcha.secret' => 'secret-key',
        ]);

        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 6: Could not resolve host captcha.example');
        });

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                return str_contains($message, 'unreachable')
                    && $context['url'] === 'https://captcha.example/siteverify'
                    && str_contains($context['error'], 'Could not resolve host');
            });

        $this->assertFalse(app(SiteVerifyCaptchaVerifier::class)->verify('the-token', '203.0.113.9'));
    }

    public function test_a_siteverify_error_status_logs_the_status_code(): void
    {
        config([
            'security.captcha.verify_url' => 'https://captcha.example/siteverify',
            'security.captcha.secret' => 'secret-key',
        ]);

        Http::fake(['https://captcha.example/siteverify' => Http::response(null, 503)]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                return $context['status'] === 503
                    && $context['url'] === 'https://captcha.example/siteverify';
            });

        $this->assertFalse(app(SiteVerifyCaptchaVerifier::class)->verify('the-token', null));
    }

    public function test_the_methods_endpoint_exposes_the_guarded_doors_and_widget_config(): void
    {
        config(['security.captcha.enabled' => false]);

        $this->getJson('/api/auth/methods')
            ->assertStatus(200)
            ->assertJsonPath('data.captcha_doors', [])
            ->assertJsonPath('data.captcha_site_key', null)
            ->assertJsonPath('data.captcha_script_url', null)
            ->assertJsonPath('data.captcha_provider', null);

        $this->enableCaptcha(['magic_link', 'password_reset']);
        config([
            'security.captcha.site_key' => 'public-site-key',
            'security.captcha.script_url' => 'https://captcha.example/api.js',
            'security.captcha.provider' => 'turnstile',
        ]);

        // The site key is public by design; the secret never rides along.
        $response = $this->getJson('/api/auth/methods')
            ->assertStatus(200)
            ->assertJsonPath('data.captcha_doors', ['magic_link', 'password_reset'])
            ->assertJsonPath('data.captcha_site_key', 'public-site-key')
            ->assertJsonPath('data.captcha_script_url', 'https://captcha.example/api.js')
            ->assertJsonPath('data.captcha_provider', 'turnstile');

        $this->assertStringNotContainsString('secret', $response->getContent());
    }
}
