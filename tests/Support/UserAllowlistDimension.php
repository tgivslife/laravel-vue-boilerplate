<?php

namespace Tests\Support;

use App\Contracts\Access\ScopeDimension;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only scope dimension over users: each actor sees exactly the user ids assigned in the
 * static map. Mirrors the shape of a deployment dimension that scopes the accounts an admin
 * may reach (county, tenant...).
 */
class UserAllowlistDimension implements ScopeDimension
{
    /**
     * @var array<int|string, list<int>>
     */
    public static array $visible = [];

    public function appliesTo(Model $model): bool
    {
        return $model instanceof User;
    }

    public function constrain(Builder $query, Authenticatable $user): void
    {
        $query->whereIn('id', self::$visible[$user->getAuthIdentifier()] ?? []);
    }

    public function allows(Authenticatable $user, Model $model): bool
    {
        return in_array((int) $model->getKey(), self::$visible[$user->getAuthIdentifier()] ?? [], true);
    }
}
