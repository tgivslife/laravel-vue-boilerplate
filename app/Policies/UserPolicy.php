<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Auth\Access\Response;

/**
 * Record-level authorization for the admin user surface.
 *
 * The capability half mirrors the route middleware (users.view / users.manage) so the policy is a complete
 * answer on its own; the record half is what the routes cannot express - scope dimensions and required-permission rules,
 * composed in AccessScope::allowsRecord().
 *
 * The tier ceilings (AccessControlService's grant and target checks) are a separate layer and stay in the service,
 * they answer rank, this answers reach, and a mutation must pass both.
 */
class UserPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'users';
    }

    /**
     * Borrowing an identity is a read of the account plus its own capability.
     * The strict impersonation tier rule stays in ImpersonationService - it is not a reach question.
     */
    public function impersonate(User $user, User $target): bool|Response
    {
        if (!$user->can('users.impersonate')) {
            return false;
        }

        return app(AccessScope::class)->allowsRecord($user, $target, 'view')
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
