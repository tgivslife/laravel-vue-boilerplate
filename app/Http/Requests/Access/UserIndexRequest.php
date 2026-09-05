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
            'filter.search' => ['sometimes', 'filled', 'string', 'max:255'],
            'filter.role_id' => [
                'sometimes', 'filled', 'integer',
                Rule::exists(config('permission.table_names.roles'), 'id')
                    ->where('guard_name', config('access.guard')),
            ],
            'filter.status' => ['sometimes', 'filled', 'string', Rule::in(['active', 'inactive', 'banned', 'deleted'])],
            'filter.two_factor' => ['sometimes', 'filled', 'string', Rule::in(['enabled', 'required', 'disabled'])],
            'filter.onboarding' => ['sometimes', 'filled', 'string', Rule::in(['invited', 'reset_pending', 'unverified'])],
            'per_page' => ['sometimes', 'filled', 'integer', Rule::in([10, 25, 50, 100])],
        ];
    }
}
