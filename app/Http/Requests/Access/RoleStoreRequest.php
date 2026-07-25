<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RoleStoreRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware (the configured
     * admin capability); the lockout invariants live in the service.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:125',
                Rule::unique(config('permission.table_names.roles'), 'name')
                    ->where('guard_name', config('access.guard')),
            ],
        ];
    }
}
