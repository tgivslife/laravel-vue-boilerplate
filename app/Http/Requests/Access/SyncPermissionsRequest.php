<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Shared by the user direct-permission and role-permission sync endpoints.
 */
final class SyncPermissionsRequest extends FormRequest
{
    /**
     * The capability is enforced by the route middleware and the lockout invariants live in the service,
     * but on the user flavor the record-scope verdict (UserPolicy) must answer here, before validation: an
     * unknown id 404s at binding, so an out-of-scope target must 404 too - not leak a 422 when the payload
     * happens to be invalid. The role flavor binds no user and stays middleware-only (roles are global vocabulary).
     */
    public function authorize(): bool|Response
    {
        $target = $this->route('user');

        return $target instanceof User ? Gate::inspect('update', $target) : true;
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
