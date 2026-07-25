<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class TwoFactorRecoveryCodesRequest extends FormRequest
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
     * Regenerating recovery codes shows a fresh plaintext set, so it is confirmed by the current password.
     * Passwordless accounts have nothing to confirm with; for them the signed-in session itself is the proof of control.
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
