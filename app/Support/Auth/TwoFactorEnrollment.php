<?php

namespace App\Support\Auth;

/**
 * What the authenticator setup screen needs to display a freshly minted (still unconfirmed) TOTP secret:
 * the manual-entry key, the otpauthURI it encodes, and that URI rendered as an inline SVG QR code.
 *
 * Produced by TwoFactorService::startEnrollment().
 */
readonly class TwoFactorEnrollment
{
    public function __construct(
        public string $secret,
        public string $otpauthUrl,
        public string $qrSvg,
    ) {
    }
}
