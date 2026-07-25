<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class IdentityUnlinkRequest extends FormRequest
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
     * Disconnecting an identity is confirmed by the current password.
     * Passwordless accounts have nothing to confirm with; for them the
     * signed-in session itself is the proof of control, and the magic-link
     * fallback means unlinking can never strand them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->user()?->getAttribute('password') !== null) {
            return [
                'password' => ['required', 'string', 'current_password:web'],
            ];
        }

        return [];
    }
}
