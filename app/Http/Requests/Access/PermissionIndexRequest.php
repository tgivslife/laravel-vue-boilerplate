<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PermissionIndexRequest extends FormRequest
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
     * `per_page` is optional on purpose: without it the endpoint returns the
     * full vocabulary, which is how the dictionary consumers (grant editors,
     * transfer lists) read it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ];
    }
}
