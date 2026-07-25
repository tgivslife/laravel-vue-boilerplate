<?php

namespace App\Models\Concerns;

use App\Models\Access\RequiredPermission;
use App\Services\Access\AccessScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Opt-in record-level authorization for protectable models.
 *
 * Nothing here is a global scope: policies call userCan() for single records, index endpoints call visibleTo() explicitly.
 * Queue jobs and console commands that never opt in are unaffected by construction.
 */
trait HasRequiredPermissions
{
    /**
     * The required-permission rules attached to this specific record.
     */
    public function requiredPermissions(): MorphMany
    {
        return $this->morphMany(RequiredPermission::class, 'protectable');
    }

    /**
     * Whether the user may perform `type` (view|update|delete) on this record:
     * scope dimensions, class-level rules and record rules, with super-admin bypass.
     * The capability check stays in the policy.
     */
    public function userCan(Authenticatable $user, string $type): bool
    {
        return app(AccessScope::class)->allowsRecord($user, $this, $type);
    }

    /**
     * Filter an index query down to the records the user may see.
     *
     * The SQL mirror of userCan(): dimension constraints, a PHP-evaluated class-level gate (short-circuits to an empty result on failure),
     * and record-rule subqueries added only when the class has record rules at all - the common case adds no subquery.
     * Fully parameterized; the only raw-ish fragment is the whereColumn correlation.
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $user, string $type = 'view'): Builder
    {
        $access = app(AccessScope::class);

        if ($access->isSuperAdmin($user)) {
            return $query;
        }

        foreach ($access->dimensions() as $dimension) {
            if ($dimension->appliesTo($this)) {
                $dimension->constrain($query, $user);
            }
        }

        $alias = $this->getMorphClass();
        $held = $access->permissionIds($user);

        if (!$access->passesGroup($access->classRules($alias, $type), $held)) {
            return $query->whereIn($this->getQualifiedKeyName(), []);
        }

        if (!$access->hasInstanceRules($alias, $type)) {
            return $query;
        }

        $rules = (new RequiredPermission)->getTable();
        $key = $this->getQualifiedKeyName();

        $correlate = function (QueryBuilder $sub, string $mode) use ($rules, $key, $alias, $type): QueryBuilder {
            return $sub->from($rules)
                ->whereColumn("{$rules}.protectable_id", $key)
                ->where("{$rules}.protectable_type", $alias)
                ->where("{$rules}.type", $type)
                ->where("{$rules}.mode", $mode);
        };

        // Every all-mode rule on the record must be held.
        $query->whereNotExists(function (QueryBuilder $sub) use ($correlate, $rules, $held) {
            $correlate($sub, RequiredPermission::MODE_ALL)
                ->whereNotIn("{$rules}.permission_id", $held);
        });

        // An any-mode group, when present, must have at least one held member.
        $query->where(function (Builder $group) use ($correlate, $rules, $held) {
            $group->whereNotExists(function (QueryBuilder $sub) use ($correlate) {
                $correlate($sub, RequiredPermission::MODE_ANY);
            })->orWhereExists(function (QueryBuilder $sub) use ($correlate, $rules, $held) {
                $correlate($sub, RequiredPermission::MODE_ANY)
                    ->whereIn("{$rules}.permission_id", $held);
            });
        });

        return $query;
    }
}
