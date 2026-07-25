<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Rejects passwords built from the account's own identity.
 *
 * Terms come from the authenticated user (password update) or the request's email field
 * (password reset, where nobody is signed in yet), plus the app name and the app-specific words in
 * `security.password_policy.context_terms`: names, the email's local part and its fragments. A password merely containing any term fails -
 * "Teodor.Ion@2026!" is a gift to anyone who knows whose account it is.
 *
 * Terms shorter than 4 characters are ignored: short names ("Ion") occur inside too many unrelated words ("dictionary") to reject on.
 */
class NotPersonalPassword implements ValidationRule, DataAwareRule
{
    private const int MINIMUM_TERM_LENGTH = 4;

    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $haystack = mb_strtolower($value);

        foreach ($this->identityTerms() as $term) {
            if (str_contains($haystack, $term)) {
                $fail('validation.password.personal')->translate();

                return;
            }
        }
    }

    /**
     * The lowercase identity fragments the password must not contain.
     *
     * @return array<int, string>
     */
    private function identityTerms(): array
    {
        $user = Auth::user();

        $emails = array_filter([
            is_string($this->data['email'] ?? null) ? $this->data['email'] : null,
            $user?->getAttribute('email'),
        ]);

        $names = array_filter([
            $user?->getAttribute('first_name'),
            $user?->getAttribute('last_name'),
        ]);

        $terms = collect((array) config('security.password_policy.context_terms', []))
            ->push((string) config('app.name'))
            ->merge($names);

        foreach ($emails as $email) {
            $localPart = Str::before($email, '@');

            $terms->push($localPart);
            $terms = $terms->merge(preg_split('/[._+\-]+/', $localPart) ?: []);
        }

        return $terms
            ->map(static fn(mixed $term): string => mb_strtolower(trim((string) $term)))
            ->filter(static fn(string $term): bool => mb_strlen($term) >= self::MINIMUM_TERM_LENGTH)
            ->unique()
            ->values()
            ->all();
    }
}
