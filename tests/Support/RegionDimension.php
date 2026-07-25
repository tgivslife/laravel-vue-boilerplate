<?php

namespace Tests\Support;

use App\Contracts\Access\ScopeDimension;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only scope dimension: widgets carry a `region` column and users are assigned a region through the static map.
 * Mirrors the shape an app-level dimension (county, tenant...) plugs into the seam.
 */
class RegionDimension implements ScopeDimension
{
    /**
     * @var array<int|string, string>
     */
    public static array $userRegions = [];

    public function appliesTo(Model $model): bool
    {
        return $model instanceof Widget;
    }

    public function constrain(Builder $query, Authenticatable $user): void
    {
        $query->where('region', self::$userRegions[$user->getAuthIdentifier()] ?? '__none__');
    }

    public function allows(Authenticatable $user, Model $model): bool
    {
        return $model->getAttribute('region') === (self::$userRegions[$user->getAuthIdentifier()] ?? null);
    }
}
