<?php

namespace Tests\Feature\Security;

use App\Support\EnvironmentSecurityChecks;
use RuntimeException;
use Tests\TestCase;

class EnvironmentSecurityChecksTest extends TestCase
{
    /**
     * Apply a configuration baseline that satisfies every assertion.
     */
    private function applyHardenedConfiguration(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://acme.example',
            'security.force_https' => true,
            'security.trusted_hosts' => ['acme.example'],
            'security.trusted_proxies' => ['10.0.0.0/8'],
            'cors.allowed_origins' => ['https://acme.example'],
            'session.secure' => true,
            'security.csp.enabled' => true,
        ]);
    }

    public function test_passes_with_a_hardened_configuration(): void
    {
        $this->applyHardenedConfiguration();

        EnvironmentSecurityChecks::assertForEnvironment('production');

        $this->addToAssertionCount(1);
    }

    public function test_skips_local_and_testing_environments(): void
    {
        config(['app.debug' => true, 'cors.allowed_origins' => ['*']]);

        EnvironmentSecurityChecks::assertForEnvironment('local');
        EnvironmentSecurityChecks::assertForEnvironment('testing');

        $this->addToAssertionCount(1);
    }

    public function test_applies_to_non_production_deployed_environments(): void
    {
        $this->applyHardenedConfiguration();
        config(['app.debug' => true]);

        try {
            EnvironmentSecurityChecks::assertForEnvironment('staging');
            $this->fail('Expected a RuntimeException for the staging environment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed for the staging environment', $exception->getMessage());
            $this->assertStringContainsString('APP_DEBUG must be false.', $exception->getMessage());
        }
    }

    public function test_rejects_disabled_https_enforcement(): void
    {
        $this->applyHardenedConfiguration();
        config(['security.force_https' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('SECURITY_FORCE_HTTPS must be enabled.');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_rejects_non_https_app_url(): void
    {
        $this->applyHardenedConfiguration();
        config(['app.url' => 'http://acme.example']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('APP_URL must use https://.');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_rejects_wildcard_cors_origins(): void
    {
        $this->applyHardenedConfiguration();
        config(['cors.allowed_origins' => ['*']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('CORS allowed origins must not use wildcard "*".');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_rejects_missing_trusted_hosts(): void
    {
        $this->applyHardenedConfiguration();
        config(['security.trusted_hosts' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('TRUSTED_HOSTS must be configured.');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_rejects_wildcard_trusted_proxies(): void
    {
        $this->applyHardenedConfiguration();
        // The config pipeline collapses a "*" entry to the bare string.
        config(['security.trusted_proxies' => '*']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('TRUSTED_PROXIES must not use wildcard "*".');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_allows_remote_addr_trusted_proxy(): void
    {
        $this->applyHardenedConfiguration();
        config(['security.trusted_proxies' => ['REMOTE_ADDR']]);

        EnvironmentSecurityChecks::assertForEnvironment('production');

        $this->addToAssertionCount(1);
    }

    public function test_allows_empty_trusted_proxies(): void
    {
        $this->applyHardenedConfiguration();
        config(['security.trusted_proxies' => []]);

        EnvironmentSecurityChecks::assertForEnvironment('production');

        $this->addToAssertionCount(1);
    }

    public function test_rejects_insecure_session_cookie(): void
    {
        $this->applyHardenedConfiguration();
        config(['session.secure' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('SESSION_SECURE_COOKIE must be true.');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_rejects_disabled_csp(): void
    {
        $this->applyHardenedConfiguration();
        config(['security.csp.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('SECURITY_CSP_ENABLED must be enabled');

        EnvironmentSecurityChecks::assertForEnvironment('production');
    }

    public function test_reports_every_failure_in_one_exception(): void
    {
        $this->applyHardenedConfiguration();
        config(['app.debug' => true, 'session.secure' => false]);

        try {
            EnvironmentSecurityChecks::assertForEnvironment('production');
            $this->fail('Expected a RuntimeException listing every failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_DEBUG must be false.', $exception->getMessage());
            $this->assertStringContainsString('SESSION_SECURE_COOKIE must be true.', $exception->getMessage());
        }
    }

    public function test_failures_lists_requirements_without_throwing(): void
    {
        $this->applyHardenedConfiguration();

        $this->assertSame([], EnvironmentSecurityChecks::failures('production'));

        config(['security.force_https' => false]);

        $this->assertSame(['SECURITY_FORCE_HTTPS must be enabled.'], EnvironmentSecurityChecks::failures('production'));
        $this->assertSame([], EnvironmentSecurityChecks::failures('local'));
    }
}
