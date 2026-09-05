<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Every id in the array names a row of the given table - answered in one query.
 *
 * The guard-free sibling of AllExistInGuard, for plain tables that carry no guard_name column;
 * same contract otherwise: applied to the array attribute itself, so a failure names the array (`tag_ids`) rather than an index,
 * and one `whereIn` count replaces the per-item `Rule::exists()` round trips.
 *
 * Shape stays the business of the `integer:strict` rule on the `.*` entries: the moment any entry is not a native
 * integer that rule has already refused the request, so this one declines to answer for an ill-shaped array rather than
 * second-guess what the shape rule admits - no value is ever reported twice, and nothing non-integer ever reaches the id query.
 *
 * This rule is the friendly 422, not the security boundary: the pivot's foreign keys refuse a phantom id even for a
 * caller that never ran this validation.
 */
final readonly class AllExist implements ValidationRule
{
    /**
     * @param  string  $table  a table whose primary key is `id`
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
            // Anything but a native integer already fails `integer:strict` on the element,
            // so the whole request is refused on shape - existence has no answer worth adding.
            if (!is_int($candidate)) {
                return;
            }

            $ids[$candidate] = true;
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return;
        }

        $found = DB::table($this->table)
            ->whereIn('id', $ids)
            ->count();

        if ($found !== count($ids)) {
            $fail('validation.exists')->translate(['attribute' => $attribute]);
        }
    }
}
