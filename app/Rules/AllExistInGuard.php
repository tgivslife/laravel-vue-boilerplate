<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Every id in the array names a row of the given table in the configured access guard - answered in one query.
 *
 * Replaces the per-item `Rule::exists()` the grant editors used to carry, which issued one SELECT per id: a role
 * saved with twenty permissions paid twenty round trips for a question a single `whereIn` count answers.
 *
 * Applied to the array attribute itself, so a failure names the array (`permission_ids`) rather than an index.
 * Keep `integer` on the `.*` entry beside it: shape is that rule's business, and entries it rejects are skipped here
 * so the two never report the same value twice.
 *
 * Candidates are normalized with the same filter_var(FILTER_VALIDATE_INT) that Laravel's `integer` rule uses, rather
 * than an is_int()/ctype_digit() approximation of it. That approximation left a hole: `integer` also accepts the float
 * 42.0 (a bare JSON 42.0 literal), '+42' and ' 42', and every one of those slipped past the approximation unchecked -
 * so a nonexistent id was silently dropped downstream instead of failing, and a foreign-guard id reached the service.
 */
final readonly class AllExistInGuard implements ValidationRule
{
    /**
     * @param  string  $table  a Spatie table name (roles / permissions), whose primary key is `id`
     */
    public function __construct(private string $table)
    {
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            return;
        }

        $ids = [];

        foreach ($value as $candidate) {
            $id = filter_var($candidate, FILTER_VALIDATE_INT);

            // Anything `integer` would reject too; that rule reports it, so this one stays quiet.
            if ($id !== false) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return;
        }

        $found = DB::table($this->table)
            ->whereIn('id', $ids)
            ->where('guard_name', config('access.guard'))
            ->count();

        if ($found !== count($ids)) {
            $fail('validation.exists')->translate(['attribute' => $attribute]);
        }
    }
}
