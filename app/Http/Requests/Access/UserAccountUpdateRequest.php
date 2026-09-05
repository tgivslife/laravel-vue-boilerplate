<?php

namespace App\Http\Requests\Access;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UserAccountUpdateRequest extends FormRequest
{
    /**
     * The capability is enforced by the route middleware, but the record-scope verdict (UserPolicy) must
     * answer here, before validation: an unknown id 404s at binding, so an out-of-scope target must 404
     * too - not leak a 422 when the payload happens to be invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('user'));
    }

    /**
     * Every field is optional: only the keys present in the request are applied, so the client can PATCH a single fact at a time.
     *
     * `require_password_reset` is deliberately prohibited: forcing a reset goes through the dedicated
     * force-password-reset endpoint, and the requirement is one-way - only the user clears it, by changing their password.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email_verified' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'banned' => ['sometimes', 'boolean'],
            'ban_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'two_factor_required' => ['sometimes', 'boolean'],
            'require_password_reset' => ['prohibited'],
        ];
    }
}
