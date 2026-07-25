<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncUserRolesRequest extends FormRequest
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
            'role_ids' => ['present', 'array'],
            'role_ids.*' => [
                'integer',
                Rule::exists(config('permission.table_names.roles'), 'id')
                    ->where('guard_name', config('access.guard')),
            ],
        ];
    }
}
