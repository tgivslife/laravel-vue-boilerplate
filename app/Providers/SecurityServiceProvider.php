<?php

namespace App\Providers;

use App\Contracts\CaptchaVerifier;
use App\Rules\NotCommonPassword;
use App\Rules\NotPersonalPassword;
use App\Services\Auth\SiteVerifyCaptchaVerifier;
use App\Support\EnvironmentSecurityChecks;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Central bootstrap for the application's security posture.
 *
 * Asserts the environment's security configuration (fail-loud outside local/testing), enforces HTTPS according to
 * `security.force_https`, and supplies the trusted proxy/host lists to their middleware.
 * The Trust* middleware are configured here rather than in bootstrap/app.php because the configuration repository
 * is not loaded when that callback runs.
 */
class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * The captcha contract defaults to the vendor-neutral siteverify implementation
     * (Turnstile/hCaptcha/reCAPTCHA); forks rebind it for anything else.
     */
    public function register(): void
    {
        $this->app->bind(CaptchaVerifier::class, SiteVerifyCaptchaVerifier::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        EnvironmentSecurityChecks::assertForEnvironment((string) $this->app->environment());

        $this->enforceHttps();
        $this->configurePasswordDefaults();

        TrustProxies::at(config('security.trusted_proxies') ?: []);
        TrustHosts::at(config('security.trusted_hosts'), subdomains: false);
    }

    /**
     * Force the HTTPS scheme when `security.force_https` is enabled.
     *
     * EnvironmentSecurityChecks guarantees the flag is true in deployed environments, so this is config-driven
     * rather than tied to a hardcoded environment list.
     * Also marks the current request as secure so isSecure()-dependent behavior matches the forced scheme
     * when TLS terminates upstream.
     */
    private function enforceHttps(): void
    {
        $enforceHttps = (bool) config('security.force_https') && !$this->app->runningUnitTests();

        URL::forceHttps($enforceHttps);

        if ($enforceHttps) {
            $this->app['request']->server->set('HTTPS', 'on');
        }
    }

    /**
     * The password policy every new-password door shares (`security.password_policy`).
     *
     * Length plus two offline denials - commonly used passwords and the account's own identity.
     * Deliberately no composition rules and no uncompromised() check: the former pushes users toward "Password1!" patterns,
     * the latter calls the HaveIBeenPwned API and this system makes no outbound requests.
     */
    private function configurePasswordDefaults(): void
    {
        Password::defaults(static fn(): Password => Password::min((int) config('security.password_policy.min_length',
            12))
            ->max(255)
            ->rules([new NotCommonPassword(), new NotPersonalPassword()]));
    }
}
