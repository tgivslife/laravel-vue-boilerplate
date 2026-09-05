<?php

namespace App\Http\Requests\Access;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for the role-surface audit feed.
 *
 * `filter[role_id]` deliberately carries no exists rule: deleted roles are the feed's reason to exist,
 * and their ids name no row anymore - an unknown id simply matches no entries.
 */
final class RoleAuditLogIndexRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filter.role_id' => ['sometimes', 'integer'],
        ];
    }
}
