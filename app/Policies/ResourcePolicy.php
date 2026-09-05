<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for vocabulary-guarded resources.
 *
 * Composes the capability check ("{resource}.view" / "{resource}.manage") with the record-level layers
 * (scope dimensions + required-permission rules).
 * Super admins never reach these methods - Gate::before answers first. Extend, implement resource(), and register per model.
 *
 * Record verbs answer in two registers on purpose. A missing capability is a plain false - a 403, and in practice the
 * route middleware has already answered it.
 * A capability held but vetoed by the record layer (scope dimension or required-permission rule) answers denyAsNotFound(),
 * an out-of-reach record must be indistinguishable from one that does not exist, or the id space becomes probeable.
 * See docs/record-scoping.md.
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

    public function view(User $user, Model $model): bool|Response
    {
        if (!$user->can($this->resource().'.view')) {
            return false;
        }

        return app(AccessScope::class)->allowsRecord($user, $model, 'view')
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->can($this->resource().'.manage');
    }

    public function update(User $user, Model $model): bool|Response
    {
        if (!$user->can($this->resource().'.manage')) {
            return false;
        }

        return app(AccessScope::class)->allowsRecord($user, $model, 'update')
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, Model $model): bool|Response
    {
        if (!$user->can($this->resource().'.manage')) {
            return false;
        }

        return app(AccessScope::class)->allowsRecord($user, $model, 'delete')
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
