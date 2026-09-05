<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the response security headers configured under `security.hsts` and `security.csp`.
 *
 * Appended to the global middleware stack so every response - SPA shell, API, health probe, rendered error pages - carries the headers.
 * Static assets served directly by the web server bypass PHP and therefore this middleware;
 * That is fine, CSP governs HTML documents and HSTS reaches browsers on every dynamic response.
 *
 * HSTS is only emitted on secure requests (browsers ignore it over plain HTTP by spec).
 * Behind the TLS-terminating ingress a request counts as secure via the trusted X-Forwarded-Proto header,
 * with `security.force_https` as the fallback.
 * The `preload` token is withheld unless its submission prerequisites hold (includeSubDomains and max-age >= one year),
 * the preload list rejects anything less.
 *
 * The CSP base policy is fixed here rather than configured: a self-hosted SPA with nonce'd scripts, no framing, and no third parties.
 * `security.csp.*_src` lists can only append sources.
 * Two source sets are derived at runtime: the captcha vendor's origin (from `security.captcha.script_url`, while the captcha is enabled)
 * and the Vite dev server's http/ws origins (while `npm run dev` is serving, so HMR works under the policy).
 * `style-src` deliberately allows 'unsafe-inline' with no nonce: Vue and its UI libraries set element styles at runtime,
 * and per spec a nonce in the directive would void 'unsafe-inline'.
 *
 * The nonce is minted here (not by Vite::useCspNonce()'s own generator) so the header stays correct even when tests swap
 * the Vite instance for a fake.
 * `Vite::useCspNonce($nonce)` stamps it on all @vite-generated tags; the inline boot script in app.blade.php reads Vite::cspNonce().
 * The ops dashboards inline their bundles and take the same nonce through Horizon::cspNonce() and Telescope::cspNonce().
 *
 * The flag-free baseline headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy) are filled only when absent,
 * so a more specific choice - EnsureJsonApiRequest's stricter `no-referrer` on API traffic - is preserved rather than clobbered by this outer layer.
 */
class SetSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure(Request): (Response)  $next  The next middleware/handler.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cspEnabled = (bool) config('security.csp.enabled', true);
        $nonce = null;

        if ($cspEnabled) {
            // Minted before $next so the nonce exists by the time any view renders.
            $nonce = Str::random(40);

            Vite::useCspNonce($nonce);
            // Both dashboards inline their whole bundle; without the nonce the strict script-src blanks them.
            Horizon::cspNonce($nonce);
            // Telescope is require-dev and registered only locally (AppServiceProvider), so the class may be absent.
            if (class_exists(Telescope::class)) {
                Telescope::cspNonce($nonce);
            }
        }

        $response = $next($request);

        $this->applyStrictTransportSecurity($request, $response);

        if ($cspEnabled) {
            $header = (bool) config('security.csp.report_only', false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($header, $this->contentSecurityPolicy($request, (string) $nonce));
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
        ];
        foreach ($headers as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Emit Strict-Transport-Security per `security.hsts`, on secure requests only.
     */
    private function applyStrictTransportSecurity(Request $request, Response $response): void
    {
        if (!(bool) config('security.hsts.enabled', true) || !$request->isSecure()) {
            return;
        }

        $maxAge = max((int) config('security.hsts.max_age', 31536000), 0);
        $includeSubdomains = (bool) config('security.hsts.include_subdomains', true);

        $directives = ["max-age={$maxAge}"];

        if ($includeSubdomains) {
            $directives[] = 'includeSubDomains';
        }

        if ((bool) config('security.hsts.preload', false) && $includeSubdomains && $maxAge >= 31536000) {
            $directives[] = 'preload';
        }

        $response->headers->set('Strict-Transport-Security', implode('; ', $directives));
    }

    /**
     * Build the Content-Security-Policy value around the per-request nonce.
     */
    private function contentSecurityPolicy(Request $request, string $nonce): string
    {
        $sources = [
            'script-src' => $this->scriptSources($request, $nonce),
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'frame-src' => [],
        ];


        foreach ([$this->captchaSources(), $this->viteDevServerSources(), $this->configuredSources()] as $extra) {
            foreach ($extra as $directive => $values) {
                $sources[$directive] = [...$sources[$directive], ...$values];
            }
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            /*
             * Without an explicit worker-src, worker creation falls back to script-src, which has no blob:
             * - and bundler-managed workers (Vite's dev client, libraries that inline theirs) are spawned from blob:
             * URLs. blob: is low-risk here: minting a blob already requires running script, so it grants an attacker nothing new;
             * the dangerous place for blob: is script-src, which stays nonce-strict.
             */
            "worker-src 'self' blob:",
        ];

        foreach ($sources as $directive => $values) {
            $values = array_values(array_unique($values));
            $directives[] = $directive.' '.implode(' ', $values === [] ? ["'none'"] : $values);
        }

        return implode('; ', $directives);
    }

    /**
     * The script-src source list for the current request.
     *
     * The app's own documents get the strict pair ('self' + nonce).
     * The Horizon and Telescope documents add 'unsafe-eval' only: both mount Vue against in-DOM templates,
     * whose runtime compilation goes through new Function().
     * Both are authorization-gated internal tools, and their inline bundles carry the nonce.
     *
     * @return list<string>
     */
    private function scriptSources(Request $request, string $nonce): array
    {
        if ($this->matchesPath($request, (string) config('horizon.path', 'horizon'))
            || $this->matchesPath($request, (string) config('telescope.path', 'telescope'))) {
            return ["'self'", "'nonce-{$nonce}'", "'unsafe-eval'"];
        }

        return ["'self'", "'nonce-{$nonce}'"];
    }

    /**
     * Whether the request targets the given base path or anything under it.
     */
    private function matchesPath(Request $request, string $path): bool
    {
        $path = trim($path, '/');

        return $path !== '' && $request->is($path, $path.'/*');
    }

    /**
     * The captcha vendor's origin while the captcha hook is enabled.
     *
     * All three supported vendors load a script, render challenge iframes and call home from the
     * widget, so the script origin joins script/frame/connect. Vendors that span additional hosts
     * (hCaptcha, reCAPTCHA) list them via `security.csp.*_src`.
     *
     * @return array<string, list<string>>
     */
    private function captchaSources(): array
    {
        if (!(bool) config('security.captcha.enabled', false)) {
            return [];
        }

        $origin = $this->originOf((string) config('security.captcha.script_url', ''));

        if ($origin === null) {
            return [];
        }

        return [
            'script-src' => [$origin],
            'frame-src' => [$origin],
            'connect-src' => [$origin],
        ];
    }

    /**
     * The Vite dev server's origins while `npm run dev` is serving assets.
     *
     * Scripts, styles, fonts and images load from the dev origin, and HMR keeps a WebSocket open to it, so
     * local development works under the same enforced policy as production.
     *
     * @return array<string, list<string>>
     */
    private function viteDevServerSources(): array
    {
        if (!Vite::isRunningHot()) {
            return [];
        }

        $devUrl = rtrim((string) file_get_contents(Vite::hotFile()));

        if ($devUrl === '') {
            return [];
        }

        // CSP host-sources cannot express IPv6 literals ([::1]), so such a dev URL cannot be allowlisted at all,
        // the browser rejects the source and blocks every dev asset.
        // vite.config.js pins server.host to 127.0.0.1 to prevent this; log loudly if it regresses.
        if (str_contains($devUrl, '[')) {
            Log::warning(
                "CSP cannot allowlist the Vite dev server URL [{$devUrl}]: IPv6 literal hosts are "
                .'inexpressible in CSP source lists and the browser will block all dev assets. '
                .'Bind the dev server to an IPv4 host (see server.host in vite.config.js).',
            );

            return [];
        }

        $devSocketUrl = (string) preg_replace('/^http/', 'ws', $devUrl);

        return [
            'script-src' => [$devUrl],
            'style-src' => [$devUrl],
            'img-src' => [$devUrl],
            'font-src' => [$devUrl],
            'connect-src' => [$devUrl, $devSocketUrl],
        ];
    }

    /**
     * Deployment-supplied extra sources from `security.csp.*_src`.
     *
     * @return array<string, list<string>>
     */
    private function configuredSources(): array
    {
        return [
            'script-src' => (array) config('security.csp.script_src', []),
            'style-src' => (array) config('security.csp.style_src', []),
            'connect-src' => (array) config('security.csp.connect_src', []),
            'frame-src' => (array) config('security.csp.frame_src', []),
            'img-src' => (array) config('security.csp.img_src', []),
            'font-src' => (array) config('security.csp.font_src', []),
        ];
    }

    /**
     * Reduce a URL to its CSP source origin (scheme://host[:port]), or null when unparseable.
     */
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = "{$parts['scheme']}://{$parts['host']}";

        if (isset($parts['port'])) {
            $origin .= ":{$parts['port']}";
        }

        return $origin;
    }
}
