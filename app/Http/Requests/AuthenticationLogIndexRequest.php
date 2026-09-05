<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Query parameters for an authentication-log page, shared by the settings history and the admin user detail page.
 * Authorization is left to the route middleware guarding each surface.
 */
final class AuthenticationLogIndexRequest extends FormRequest
{
    /**
     * On the admin flavor (a bound {user}) the record-scope verdict (UserPolicy) must answer here, before validation,
     * an unknown id 404s at binding, so an out-of-scope target must 404 too - not leak a 422 when the query string happens to be invalid.
     * The settings flavor binds no user (the actor reads their own history) and stays middleware-only.
     */
    public function authorize(): bool|Response
    {
        $target = $this->route('user');

        return $target instanceof User ? Gate::inspect('view', $target) : true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'filled', 'date_format:Y-m-d'],
        ];
    }
}
