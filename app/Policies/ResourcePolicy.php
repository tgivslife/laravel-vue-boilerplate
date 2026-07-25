<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for vocabulary-guarded resources.
 *
 * Composes the capability check ("{resource}.view" / "{resource}.manage")
 * with the record-level layers (scope dimensions + required-permission
 * rules). Super admins never reach these methods - Gate::before answers
 * first. Extend, implement resource(), and register per model.
 */
abstract class ResourcePolicy
{
    /**
     * The vocabulary resource this policy guards (e.g. 'users').
     */
    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->resource().'.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->resource().'.view')
            && app(AccessScope::class)->allowsRecord($user, $model, 'view');
    }

    public function create(User $user): bool
    {
        return $user->can($this->resource().'.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->resource().'.manage')
            && app(AccessScope::class)->allowsRecord($user, $model, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->resource().'.manage')
            && app(AccessScope::class)->allowsRecord($user, $model, 'delete');
    }
}
