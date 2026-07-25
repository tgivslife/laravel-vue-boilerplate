<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserIndexRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware (the configured
     * admin capability).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the filter values; the allowed filter keys themselves are
     * enforced by QueryBuilder, which rejects unknown filters with a 400.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.role_id' => [
                'sometimes', 'integer',
                Rule::exists(config('permission.table_names.roles'), 'id')
                    ->where('guard_name', config('access.guard')),
            ],
            'filter.status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'banned', 'deleted'])],
            'filter.two_factor' => ['sometimes', 'string', Rule::in(['enabled', 'required', 'disabled'])],
            'filter.onboarding' => ['sometimes', 'string', Rule::in(['invited', 'reset_pending', 'unverified'])],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ];
    }
}
