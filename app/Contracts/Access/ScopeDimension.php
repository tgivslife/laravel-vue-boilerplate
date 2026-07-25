<?php

namespace App\Contracts\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An application-defined visibility dimension (tenant, region, department...)
 * composed into every record-level access decision alongside capabilities
 * and required-permission rules.
 *
 * Implementations are listed in config/access.php `dimensions` and resolved
 * from the container. Both forms of the same question must agree: constrain()
 * narrows index queries to exactly the records allows() would accept.
 */
interface ScopeDimension
{
    /**
     * Whether this dimension has jurisdiction over the given model.
     */
    public function appliesTo(Model $model): bool;

    /**
     * Narrow an index query to the records the user may see.
     */
    public function constrain(Builder $query, Authenticatable $user): void;

    /**
     * Whether the user may act on this specific record.
     */
    public function allows(Authenticatable $user, Model $model): bool;
}
