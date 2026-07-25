<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PersonalAccessTokenStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The password is re-confirmed against the session user so a hijacked
     * browser tab cannot silently mint a long-lived credential. Abilities
     * must be permission names the user actually holds - a token can only
     * ever narrow its owner's access, never extend it. Omitting them
     * yields an unscoped ['*'] token.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => [
                'string',
                Rule::in($this->user()->getAllPermissions()->pluck('name')->all()),
            ],
            'expires_in_days' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.(int) config('security.personal_access_tokens.max_lifetime_days', 365),
            ],
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }
}
