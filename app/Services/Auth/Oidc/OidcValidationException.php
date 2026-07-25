<?php

namespace App\Services\Auth\Oidc;

use RuntimeException;

/**
 * An OIDC response failed validation (signature, issuer, audience, nonce, or a malformed discovery/token payload).
 * Deliberately carries no user-facing detail: callers translate every failure into one generic
 * "sign-in failed" outcome so the callback cannot become an oracle.
 */
class OidcValidationException extends RuntimeException
{
}
