<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccountDeleteRequest extends FormRequest
{
    use NormalizesEmail;

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
     * Deleting the account is confirmed by the current password. Accounts
     * that sign in through emailed links have no password, so they type
     * their email address instead as the deliberate-action check.
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

        return [
            'email' => ['required', 'string', Rule::in([(string) $this->user()?->email])],
        ];
    }
}
