<?php

namespace App\Support\Access;

use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * QueryBuilder search filter: a bound, case-insensitive LIKE across one or more columns with `%`, `_` and `\` escaped,
 * so user input can never widen the match. Backs the `filter[search]` parameter of the access browsers.
 *
 * Case-insensitivity is explicit (whereLike) rather than inherited from the driver: plain LIKE is case-sensitive on
 * PostgreSQL but not on SQLite, and a search box must not behave differently per deployment.
 *
 * @implements Filter<\Illuminate\Database\Eloquent\Model>
 */
final readonly class EscapedLikeFilter implements Filter
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        private array $columns
    ) {
    }

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $term = trim(is_array($value) ? implode(',', $value) : (string) $value);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $searchQuery) use ($like): void {
            foreach ($this->columns as $column) {
                $searchQuery->orWhereLike($column, $like);
            }
        });
    }
}
