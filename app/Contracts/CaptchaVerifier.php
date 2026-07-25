<?php

namespace App\Contracts;

/**
 * Verifies an anti-abuse challenge token submitted with a public-door request.
 *
 * The container binding (SecurityServiceProvider) decides the vendor: the shipped SiteVerifyCaptchaVerifier speaks
 * the siteverify protocol Turnstile, hCaptcha and reCAPTCHA share; forks rebind this contract for anything else.
 */
interface CaptchaVerifier
{
    public function verify(string $token, ?string $ipAddress): bool;
}
