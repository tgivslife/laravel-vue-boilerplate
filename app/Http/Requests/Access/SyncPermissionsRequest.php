<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the user direct-permission and role-permission sync endpoints.
 */
final class SyncPermissionsRequest extends FormRequest
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
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => [
                'integer',
                Rule::exists(config('permission.table_names.permissions'), 'id')
                    ->where('guard_name', config('access.guard')),
            ],
        ];
    }
}
