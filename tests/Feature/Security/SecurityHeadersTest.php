<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

/**
 * SetSecurityHeaders: HSTS emission (secure requests only, preload prerequisites), the Content-Security-Policy
 * (base policy, nonce, report-only, captcha/dev-server/config-derived sources) and the fill-if-absent baseline headers.
 *
 * The health endpoint stands in for "any response through the global stack"; the SPA-shell tests point Vite at a
 * fake hot file so app.blade.php renders without a build manifest - which also exercises the dev-server source derivation.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_csp_and_baseline_headers_are_emitted(): void
    {
        $response = $this->get('https://localhost/up');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString("worker-src 'self' blob:", $policy);
        $this->assertStringContainsString("frame-src 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $policy);
    }

    public function test_api_responses_keep_their_stricter_referrer_policy(): void
    {
        $response = $this->getJson('https://localhost/api/auth/methods');

        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_csp_can_run_in_report_only_mode(): void
    {
        config(['security.csp.report_only' => true]);

        $response = $this->get('https://localhost/up');

        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy-Report-Only'),
        );
    }

    public function test_csp_can_be_disabled(): void
    {
        config(['security.csp.enabled' => false]);

        $response = $this->get('https://localhost/up');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_captcha_vendor_origin_joins_script_frame_and_connect_sources(): void
    {
        config([
            'security.captcha.enabled' => true,
            'security.captcha.script_url' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        ]);

        $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/script-src [^;]*https:\/\/challenges\.cloudflare\.com/', $policy);
        $this->assertMatchesRegularExpression('/frame-src [^;]*https:\/\/challenges\.cloudflare\.com/', $policy);
        $this->assertMatchesRegularExpression('/connect-src [^;]*https:\/\/challenges\.cloudflare\.com/', $policy);
        $this->assertStringNotContainsString("frame-src 'none'", $policy);
    }

    public function test_configured_extra_sources_are_appended(): void
    {
        config(['security.csp.script_src' => ['https://cdn.example.com']]);

        $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/script-src [^;]*https:\/\/cdn\.example\.com/', $policy);
    }

    public function test_spa_shell_inline_script_carries_the_header_nonce(): void
    {
        $hotFile = $this->fakeViteHotFile('http://localhost:5173');

        try {
            $response = $this->get('https://localhost/');

            $policy = (string) $response->headers->get('Content-Security-Policy');
            $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9]{40})'/", $policy, $matches));

            $response->assertSee('nonce="'.$matches[1].'"', false);
        } finally {
            File::delete($hotFile);
        }
    }

    public function test_vite_dev_server_origins_are_allowed_while_running_hot(): void
    {
        $hotFile = $this->fakeViteHotFile('http://localhost:5173');

        try {
            $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

            $this->assertMatchesRegularExpression('/script-src [^;]*http:\/\/localhost:5173/', $policy);
            $this->assertMatchesRegularExpression('/style-src [^;]*http:\/\/localhost:5173/', $policy);
            $this->assertMatchesRegularExpression('/connect-src [^;]*ws:\/\/localhost:5173/', $policy);
            // A dependency's web worker is served from the dev origin rather than bundled into the app's
            // own, so without this the browser blocks it and the feature dies in development only -
            // the built deployment serves it from 'self' and never notices.
            $this->assertMatchesRegularExpression('/worker-src [^;]*http:\/\/localhost:5173/', $policy);
        } finally {
            File::delete($hotFile);
        }
    }

    public function test_worker_src_keeps_its_baseline_while_the_dev_server_extends_it(): void
    {
        $hotFile = $this->fakeViteHotFile('http://localhost:5173');

        try {
            $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

            // The dev origin is an addition, never a replacement: 'self' and blob: still carry the
            // app's own workers and the ones libraries mint at runtime.
            $this->assertMatchesRegularExpression("/worker-src [^;]*'self'/", $policy);
            $this->assertMatchesRegularExpression('/worker-src [^;]*blob:/', $policy);
        } finally {
            File::delete($hotFile);
        }
    }

    public function test_an_ipv6_dev_server_url_is_excluded_and_logged_rather_than_emitted_invalid(): void
    {
        $hotFile = $this->fakeViteHotFile('http://[::1]:5173');

        try {
            Log::shouldReceive('warning')->once()->withArgs(
                static fn(string $message): bool => str_contains($message, 'http://[::1]:5173'),
            );

            $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

            // CSP cannot express IPv6 literal hosts; an invalid source would be browser-rejected anyway.
            $this->assertStringNotContainsString('[::1]', $policy);
        } finally {
            File::delete($hotFile);
        }
    }

    public function test_the_nonce_is_shared_with_horizon_inline_assets(): void
    {
        $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9]{40})'/", $policy, $matches));

        // Horizon inlines its dashboard bundle; without the header's nonce it would be blocked.
        $this->assertSame(' nonce="'.$matches[1].'"', Horizon::$nonceAttribute);
    }

    public function test_horizon_documents_add_unsafe_eval_but_keep_the_nonce(): void
    {
        $policy = (string) $this->get('https://localhost/horizon/dashboard')
            ->headers->get('Content-Security-Policy');

        // Horizon mounts Vue against in-DOM templates (runtime compiler => eval), nonce'd via Horizon::cspNonce().
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}' 'unsafe-eval'/", $policy);
    }

    public function test_telescope_documents_add_unsafe_eval_but_keep_the_nonce(): void
    {
        $policy = (string) $this->get('https://localhost/telescope/requests')
            ->headers->get('Content-Security-Policy');

        // Same shape as Horizon: Telescope inlines its bundle and takes the nonce via Telescope::cspNonce().
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9]{40})' 'unsafe-eval'/", $policy, $matches));
        $this->assertStringNotContainsString("'unsafe-inline' 'unsafe-eval'", $policy);
        $this->assertSame(' nonce="'.$matches[1].'"', Telescope::$nonceAttribute);
    }

    public function test_the_strict_script_policy_has_no_eval_outside_the_dashboards(): void
    {
        $policy = (string) $this->get('https://localhost/up')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function test_hsts_is_emitted_on_secure_requests(): void
    {
        $response = $this->get('https://localhost/up');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_is_absent_on_insecure_requests(): void
    {
        $response = $this->get('http://localhost/up');

        $response->assertHeaderMissing('Strict-Transport-Security');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_is_emitted_for_forwarded_https_from_a_trusted_proxy(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $response = $this->get('http://localhost/up', ['X-Forwarded-Proto' => 'https']);

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_can_be_disabled(): void
    {
        config(['security.hsts.enabled' => false]);

        $this->get('https://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_preload_requires_its_submission_prerequisites(): void
    {
        config(['security.hsts.preload' => true, 'security.hsts.max_age' => 300]);

        $this->get('https://localhost/up')
            ->assertHeader('Strict-Transport-Security', 'max-age=300; includeSubDomains');

        config(['security.hsts.max_age' => 31536000]);

        $this->get('https://localhost/up')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }

    /**
     * Point Vite at a fake hot file so @vite renders dev-server tags (no build manifest needed).
     *
     * @return string The hot file path, for cleanup.
     */
    private function fakeViteHotFile(string $devServerUrl): string
    {
        $hotFile = storage_path('framework/testing/vite.hot');

        File::ensureDirectoryExists(dirname($hotFile));
        File::put($hotFile, $devServerUrl);
        Vite::useHotFile($hotFile);

        return $hotFile;
    }
}
