<?php

namespace App\Services\Auth;

use App\Contracts\CaptchaVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The vendor-neutral default verifier: Cloudflare Turnstile, hCaptcha and Google reCAPTCHA all accept POST {secret, response, remoteip}
 * against a "siteverify" endpoint and answer {"success": bool}, so one HTTP call covers all three - pick the vendor with
 * `security.captcha.verify_url` + `security.captcha.secret`, no SDK required.
 *
 * Fails loudly when the feature is on but unconfigured (a missing secret is an operator error to surface, not to wave through),
 * and fails closed on transport errors - a captcha outage must not silently disable the anti-abuse gate.
 * The client only ever sees the generic refusal, so transport failures are logged here: the server log is the one place
 * an unreachable or misbehaving siteverify endpoint stays diagnosable.
 */
readonly class SiteVerifyCaptchaVerifier implements CaptchaVerifier
{
    public function verify(string $token, ?string $ipAddress): bool
    {
        $url = (string) config('security.captcha.verify_url');
        $secret = (string) config('security.captcha.secret');

        if ($url === '' || $secret === '') {
            throw new RuntimeException(
                'Captcha is enabled but security.captcha.verify_url / security.captcha.secret are not configured.'
            );
        }

        try {
            $response = Http::asForm()->post($url, [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ipAddress,
            ]);
        } catch (ConnectionException $exception) {
            Log::error('Captcha siteverify endpoint is unreachable; failing closed.', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if (!$response->successful()) {
            Log::error('Captcha siteverify endpoint answered an error status; failing closed.', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return false;
        }

        return (bool) ($response->json('success') ?? false);
    }
}
