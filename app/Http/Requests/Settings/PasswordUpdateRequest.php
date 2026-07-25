<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class PasswordUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authentication is enforced by the route middleware (auth:sanctum +
     * EnsureSessionAuthenticated) before this request runs, and the action
     * only ever touches the authenticated user's own row - there is no
     * further authorization question to answer here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Changing the password is confirmed with the current one. Accounts
     * that sign in through emailed links have no password yet; for them
     * this endpoint sets the first one, and the signed-in session (which
     * proved mailbox ownership) is the confirmation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'revoke_other_sessions' => ['sometimes', 'boolean'],
        ];

        if ($this->user()?->getAttribute('password') !== null) {
            $rules['current_password'] = ['required', 'string', 'current_password:web'];
        }

        return $rules;
    }
}
