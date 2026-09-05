<?php

namespace App\Http\Requests\Concerns;

/**
 * Lowercases and trims the `email` input before validation.
 *
 * Emails are lowercase at rest (User model mutator), but lookups compare with `=` under case-sensitive collations (pgsql, sqlite),
 * so every request that looks up, dedupes or matches an account by typed email must normalize the same way, or case-variant spellings
 * miss existing accounts (login, magic link, password reset) and slip past unique rules.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
