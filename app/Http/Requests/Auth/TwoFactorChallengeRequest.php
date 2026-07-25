<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class TwoFactorChallengeRequest extends FormRequest
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
     * Exactly one of the two fields: a 6-digit authenticator code or a recovery code.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['required_without:code', 'string', 'max:32'],
        ];
    }
}
