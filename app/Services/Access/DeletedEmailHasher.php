<?php

namespace App\Services\Access;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tombstones deleted accounts' email addresses.
 *
 * On deletion the email column becomes an unroutable placeholder ({uuid}@deleted.invalid), so the address leaves
 * the unique index and can become an account again, while its APP_KEY-keyed HMAC is kept in `deleted_email_hash`,
 * "was this address ever an account?" stays answerable (User::whereDeletedEmail()) without retaining the address itself.
 * Addresses are normalized (trimmed, lowercased) before hashing so membership lookups are case-insensitive.
 */
readonly class DeletedEmailHasher
{
    private string $key;

    public function __construct()
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new RuntimeException('An application key is required to hash deleted email addresses.');
        }

        $this->key = $key;
    }

    public function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $this->key);
    }

    /**
     * An unroutable, collision-free replacement address (`.invalid` is reserved by RFC 2606).
     */
    public function tombstoneAddress(): string
    {
        return Str::uuid()->toString().'@deleted.invalid';
    }
}
