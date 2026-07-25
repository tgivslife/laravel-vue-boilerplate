<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the class-level and per-record rule sync endpoints. An empty
 * permission_ids list clears the group for the given type.
 */
final class RuleSyncRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware (the configured
     * admin capability); the protectable whitelist is re-checked in the
     * service against the enforced morph map.
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
            'type' => ['required', 'string', Rule::in(config('access.rule_types'))],
            'mode' => ['required', 'string', Rule::in(['all', 'any'])],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => [
                'integer',
                Rule::exists(config('permission.table_names.permissions'), 'id')
                    ->where('guard_name', config('access.guard')),
            ],
        ];
    }
}
