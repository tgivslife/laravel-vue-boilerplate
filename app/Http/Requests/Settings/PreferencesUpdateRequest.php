<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class PreferencesUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Built from the closed registry at config/settings.php (preferences): each registered key validates with its
     * registry rules when present, and any unregistered key is prohibited, the column must never hold state the registry does not know.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $registry = (array) config('settings.preferences');

        $rules = array_map(
            static fn(array $entry): array => ['sometimes', ...(array) ($entry['rules'] ?? [])],
            $registry,
        );

        // Unknown keys are detected in the JSON body only: $this->all() would merge query-string parameters,
        // and a stray tracking parameter must not 422 an otherwise valid preference update.
        foreach (array_diff(array_keys($this->json()->all()), array_keys($registry)) as $unknown) {
            $rules[$unknown] = ['prohibited'];
        }

        return $rules;
    }
}
