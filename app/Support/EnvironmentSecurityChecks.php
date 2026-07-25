<?php

namespace App\Support;

use RuntimeException;

/**
 * Fail-loud security configuration assertions for deployed environments.
 *
 * Applies to every environment except `local` and `testing`, so staging and beta deployments are held to the same baseline as production.
 * Throwing at boot is intentional: a misconfigured deployment should fail immediately and visibly rather than run with weakened auth guarantees.
 *
 * Every violated requirement is collected before throwing, so one crashed boot reports the full list instead of one problem per deploy attempt.
 * `security:diagnose` reuses {@see failures()} to itemize the same checks (plus softer warnings) at container startup without booting to a crash.
 */
class EnvironmentSecurityChecks
{
    /**
     * Environments exempt from the assertions.
     *
     * @var list<string>
     */
    private const array EXEMPT_ENVIRONMENTS = ['local', 'testing'];

    public static function assertForEnvironment(string $environment): void
    {
        $failures = self::failures($environment);

        if ($failures === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Environment security checks failed for the %s environment (%d failure%s):\n%s",
            $environment,
            count($failures),
            count($failures) === 1 ? '' : 's',
            implode("\n", array_map(static fn(string $requirement): string => "  - {$requirement}", $failures)),
        ));
    }

    /**
     * Every security requirement the current configuration violates, empty when compliant or exempt.
     *
     * @return list<string>
     */
    public static function failures(string $environment): array
    {
        if (in_array($environment, self::EXEMPT_ENVIRONMENTS, true)) {
            return [];
        }

        $failures = [];

        if ((bool) config('app.debug', false)) {
            $failures[] = 'APP_DEBUG must be false.';
        }

        $appUrl = mb_strtolower((string) config('app.url', ''));
        if (!str_starts_with($appUrl, 'https://')) {
            $failures[] = 'APP_URL must use https://.';
        }

        if (!(bool) config('security.force_https', false)) {
            $failures[] = 'SECURITY_FORCE_HTTPS must be enabled.';
        }

        $allowedOrigins = config('cors.allowed_origins', []);
        if (is_array($allowedOrigins) && in_array('*', $allowedOrigins, true)) {
            $failures[] = 'CORS allowed origins must not use wildcard "*".';
        }

        $trustedHosts = config('security.trusted_hosts', []);
        if (!is_array($trustedHosts) || $trustedHosts === []) {
            $failures[] = 'TRUSTED_HOSTS must be configured.';
        }

        $trustedProxies = config('security.trusted_proxies', []);
        if (!is_array($trustedProxies)) {
            $failures[] = 'TRUSTED_PROXIES must not use wildcard "*". List explicit proxy addresses, '
                .'or use "REMOTE_ADDR" behind a platform proxy that overwrites forwarded headers.';
        }

        if (!(bool) config('session.secure', false)) {
            $failures[] = 'SESSION_SECURE_COOKIE must be true.';
        }

        if (!(bool) config('security.csp.enabled', true)) {
            $failures[] = 'SECURITY_CSP_ENABLED must be enabled '
                .'(SECURITY_CSP_REPORT_ONLY=true rehearses the policy without enforcement).';
        }

        return $failures;
    }
}
