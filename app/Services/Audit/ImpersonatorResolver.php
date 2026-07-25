<?php

namespace App\Services\Audit;

use App\Services\Access\ImpersonationService;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

/**
 * Fills the audits.impersonator_id column: the administrator whose session is borrowed when a write happens mid-impersonation,
 * read from the impersonation session marker.
 * The user resolver still records the target - the audit trail's actor is never borrowed, it is widened to name both parties.
 */
class ImpersonatorResolver implements Resolver
{
    public static function resolve(Auditable $auditable): ?int
    {
        $request = request();

        if ($request === null) {
            return null;
        }

        return app(ImpersonationService::class)->state($request)['actor_id'] ?? null;
    }
}
