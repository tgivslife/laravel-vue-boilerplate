<?php

namespace App\Http\Requests\Access;

use App\Rules\AllExistInGuard;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class SyncUserRolesRequest extends FormRequest
{
    /**
     * The capability is enforced by the route middleware and the lockout invariants live in the service,
     * but the record-scope verdict (UserPolicy) must answer here, before validation: an unknown id 404s at
     * binding, so an out-of-scope target must 404 too - not leak a 422 when the payload happens to be invalid.
     * The bare inspect (no instanceof guard) is safe only while this request serves exactly one route, which
     * binds {user}; guard the target like SyncPermissionsRequest does before reusing it elsewhere.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('user'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_ids' => [
                'present', 'array',
                new AllExistInGuard(config('permission.table_names.roles')),
            ],
            'role_ids.*' => ['integer:strict'],
        ];
    }
}
