<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Keyed hashing for magic-link token secrets.
 *
 * The application key is used as an HMAC-SHA256 key, so a leaked database
 * alone cannot be used to recognize or forge tokens - the attacker would also
 * need APP_KEY. The raw secret is never persisted anywhere.
 */
readonly class MagicLinkTokenHasher
{
    private string $key;

    public function __construct()
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new RuntimeException('An application key is required to hash magic-link tokens.');
        }

        $this->key = $key;
    }

    public function hash(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->key);
    }
}
