<?php

namespace App\Console\Commands\Ops;

use App\Support\EnvironmentSecurityChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;

/**
 * Startup diagnostics for the deployment's security configuration.
 *
 * Meant to run from the container entrypoint (after config:cache, before the workers start) so a misconfigured deployment
 * dies with the complete list of problems in the container logs instead of serving weakened traffic,
 * and so the softer topology mistakes that boot cannot fail on (empty proxy list, host/domain mismatches, rollout-mode headers) are at least on record.
 *
 * Failures are the hard boot requirements (EnvironmentSecurityChecks) plus configuration that is guaranteed broken at first use:
 * an enabled captcha missing its keys, a missing Vite build, a leftover hot file pointing the SPA at a dev server.
 * Warnings flag configurations that run but misbehave; notes record deliberate rollout states (small HSTS max-age, report-only CSP).
 * Everything is mirrored to the log channel for later debugging.
 *
 * Exit code: non-zero on any failure; `--strict` promotes warnings to the same treatment.
 * Configuration coherence only - liveness (database, Redis, mail) belongs to the health probes.
 */
#[Signature('security:diagnose {--strict : Exit non-zero on warnings too}')]
#[Description('Verify the security configuration and report misconfigurations')]
class SecurityDiagnoseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $environment = (string) $this->laravel->environment();
        $deployed = !in_array($environment, ['local', 'testing'], true);

        $failures = EnvironmentSecurityChecks::failures($environment);
        $warnings = [];
        $notes = [];

        $this->checkCaptcha($failures);
        $this->checkViteArtifacts($deployed, $failures);
        $this->checkTrustedProxies($deployed, $warnings);
        $this->checkHostCoherence($warnings);
        $this->checkStrictTransportSecurity($deployed, $warnings, $notes);
        $this->checkContentSecurityPolicy($deployed, $warnings, $notes);

        $this->report($environment, $failures, $warnings, $notes);

        if ($failures !== [] || ($warnings !== [] && (bool) $this->option('strict'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * An enabled captcha without both keys fails closed on every protected door at first use;
     * surface that at startup instead.
     *
     * @param  list<string>  $failures
     */
    private function checkCaptcha(array &$failures): void
    {
        if (!(bool) config('security.captcha.enabled', false)) {
            return;
        }

        if (!config('security.captcha.secret') || !config('security.captcha.site_key')) {
            $failures[] = 'CAPTCHA_ENABLED is true but CAPTCHA_SECRET and CAPTCHA_SITE_KEY are not both set; '
                .'every captcha-protected door would fail closed at first use.';
        }
    }

    /**
     * The two frontend-bundle mistakes that present as a blank SPA page in a deployed container.
     *
     * @param  list<string>  $failures
     */
    private function checkViteArtifacts(bool $deployed, array &$failures): void
    {
        if (!$deployed) {
            return;
        }

        if (Vite::isRunningHot()) {
            $failures[] = sprintf(
                'Vite hot file present at [%s]; @vite would load assets from a dev server that does not exist here. '
                .'Exclude it from the image.',
                Vite::hotFile(),
            );

            return;
        }

        if (!is_file(public_path('build/manifest.json'))) {
            $failures[] = 'Vite build manifest missing at [public/build/manifest.json]; '
                .'run "npm run build" when building the image.';
        }
    }

    /**
     * An empty proxy list behind an ingress means forwarded headers are ignored: requests read as
     * plain HTTP from the ingress IP, and IP-keyed protections (login lockout, rate limiters)
     * collapse every client into one bucket.
     *
     * @param  list<string>  $warnings
     */
    private function checkTrustedProxies(bool $deployed, array &$warnings): void
    {
        if ($deployed && config('security.trusted_proxies', []) === []) {
            $warnings[] = 'TRUSTED_PROXIES is empty: X-Forwarded-* headers are ignored, so behind an ingress every '
                .'client shares the ingress IP and IP-keyed limits (login lockout) collapse into one bucket. '
                .'Use "REMOTE_ADDR" or the ingress CIDR.';
        }
    }

    /**
     * The APP_URL host must agree with the host allowlist, Sanctum's stateful domains and the
     * session cookie domain, or the app rejects or de-authenticates its own canonical traffic.
     *
     * @param  list<string>  $warnings
     */
    private function checkHostCoherence(array &$warnings): void
    {
        $appHost = (string) parse_url((string) config('app.url', ''), PHP_URL_HOST);

        if ($appHost === '') {
            return;
        }

        $trustedHosts = config('security.trusted_hosts', []);
        if (is_array($trustedHosts) && $trustedHosts !== [] && !in_array($appHost, $trustedHosts, true)) {
            $warnings[] = sprintf(
                'APP_URL host [%s] is not in TRUSTED_HOSTS [%s]; the app would reject its own canonical host.',
                $appHost,
                implode(', ', $trustedHosts),
            );
        }

        $statefulDomains = (array) config('sanctum.stateful', []);
        $statefulHosts = array_map(
            static fn(string $domain): string => strtok(trim($domain), ':') ?: '',
            $statefulDomains,
        );
        if (!in_array($appHost, $statefulHosts, true)) {
            $warnings[] = sprintf(
                'APP_URL host [%s] is not covered by SANCTUM_STATEFUL_DOMAINS [%s]; '
                .'browser sessions would not authenticate (401/419 on the SPA).',
                $appHost,
                implode(', ', $statefulDomains),
            );
        }

        $sessionDomain = (string) (config('session.domain') ?? '');
        if ($sessionDomain !== '' && !str_ends_with($appHost, ltrim($sessionDomain, '.'))) {
            $warnings[] = sprintf(
                'SESSION_DOMAIN [%s] does not match the APP_URL host [%s]; the browser would drop the session cookie.',
                $sessionDomain,
                $appHost,
            );
        }
    }

    /**
     * HSTS posture: disabled is a warning, a withheld preload token explains itself, and a small
     * max-age is recorded as the deliberate rollout state it should be.
     *
     * @param  list<string>  $warnings
     * @param  list<string>  $notes
     */
    private function checkStrictTransportSecurity(bool $deployed, array &$warnings, array &$notes): void
    {
        if (!(bool) config('security.hsts.enabled', true)) {
            if ($deployed) {
                $warnings[] = 'SECURITY_HSTS_ENABLED is off: browsers keep accepting plain-HTTP and '
                    .'click-through-able certificate errors for this host.';
            }

            return;
        }

        $maxAge = (int) config('security.hsts.max_age', 31536000);
        $includeSubdomains = (bool) config('security.hsts.include_subdomains', true);

        if ((bool) config('security.hsts.preload', false) && (!$includeSubdomains || $maxAge < 31536000)) {
            $warnings[] = 'SECURITY_HSTS_PRELOAD is set but its prerequisites are not '
                .'(includeSubDomains and max-age >= 31536000); the preload token is being withheld from the header.';
        }

        if ($maxAge < 31536000) {
            $notes[] = sprintf(
                'HSTS max-age is %d (rollout mode); raise SECURITY_HSTS_MAX_AGE to 31536000 once TLS at the edge is proven stable.',
                $maxAge,
            );
        }
    }

    /**
     * CSP posture: report-only is a legitimate rollout state, but one to be reminded of.
     * A disabled CSP in a deployed environment is already a boot failure via EnvironmentSecurityChecks.
     *
     * @param  list<string>  $warnings
     * @param  list<string>  $notes
     */
    private function checkContentSecurityPolicy(bool $deployed, array &$warnings, array &$notes): void
    {
        if (!(bool) config('security.csp.enabled', true)) {
            if (!$deployed) {
                $notes[] = 'CSP is disabled (allowed outside deployed environments; boot rejects this in them).';
            }

            return;
        }

        if ((bool) config('security.csp.report_only', false)) {
            $message = 'CSP is report-only: violations surface in the browser console but nothing is blocked. '
                .'Set SECURITY_CSP_REPORT_ONLY=false once a click-through of the app is clean.';

            $deployed ? $warnings[] = $message : $notes[] = $message;
        }
    }

    /**
     * Print every finding and mirror it to the log channel, worst first.
     *
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     * @param  list<string>  $notes
     */
    private function report(string $environment, array $failures, array $warnings, array $notes): void
    {
        foreach ($failures as $failure) {
            $this->error("[FAIL] {$failure}");
            Log::error("security:diagnose failure: {$failure}");
        }

        foreach ($warnings as $warning) {
            $this->warn("[WARN] {$warning}");
            Log::warning("security:diagnose warning: {$warning}");
        }

        foreach ($notes as $note) {
            $this->line("[NOTE] {$note}");
            Log::info("security:diagnose note: {$note}");
        }

        $summary = sprintf(
            'security:diagnose (%s environment): %d failure(s), %d warning(s), %d note(s).',
            $environment,
            count($failures),
            count($warnings),
            count($notes),
        );

        if ($failures === []) {
            $this->info($summary);
        } else {
            $this->error($summary);
        }

        Log::log($failures === [] ? 'info' : 'error', $summary);
    }
}
