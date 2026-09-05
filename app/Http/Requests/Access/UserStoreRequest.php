<?php

namespace App\Http\Requests\Access;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Rules\AllExistInGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UserStoreRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * Authorization is enforced by the route middleware (the users.manage
     * capability); the lockout invariants live in the service.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Email uniqueness spans soft-deleted rows too - the database index
     * does, so validation must match it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'delivery' => ['sometimes', 'string', Rule::in($this->allowedDeliveries())],
            'role_ids' => [
                'sometimes', 'array',
                new AllExistInGuard(config('permission.table_names.roles')),
            ],
            'role_ids.*' => ['integer:strict'],
        ];
    }

    /**
     * The `delivery` rule only fires when the field is present; the resolved fallback must honor the deployment's switches too,
     * or a link-only deployment with invitations disabled would mail a link the consume side (correctly) refuses.
     * This is also what turns "no delivery mode is possible at all" into a plain validation error instead of a half-created account.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('delivery') || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (!in_array($this->fallbackDelivery(), $this->allowedDeliveries(), true)) {
                $validator->errors()->add('delivery', __('validation.in', ['attribute' => 'delivery']));
            }
        });
    }

    /**
     * The delivery to use: the validated choice, or the deployment's default when omitted
     * ({@see fallbackDelivery()}), which withValidator() has already confirmed is available.
     */
    public function delivery(): string
    {
        return (string) ($this->validated('delivery') ?? $this->fallbackDelivery());
    }

    /**
     * The default when no delivery was requested: a temporary password where the password door exists
     * (the pre-invitations behavior), an invitation otherwise (a password nobody can type is a trap).
     */
    private function fallbackDelivery(): string
    {
        return (bool) config('security.password_login.enabled', true) ? 'temporary_password' : 'invitation';
    }

    /**
     * The onboarding deliveries this deployment can honor: a temporary password needs the password door, an invitation needs its feature switch.
     *
     * @return list<string>
     */
    private function allowedDeliveries(): array
    {
        $allowed = [];

        if ((bool) config('security.password_login.enabled', true)) {
            $allowed[] = 'temporary_password';
        }

        if ((bool) config('security.invitations.enabled', true)) {
            $allowed[] = 'invitation';
        }

        return $allowed;
    }
}
