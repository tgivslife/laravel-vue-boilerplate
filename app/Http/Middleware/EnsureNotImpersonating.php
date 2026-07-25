<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonErrorResponse;
use App\Services\Access\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes a route to sessions whose identity is borrowed (admin impersonation).
 *
 * While impersonating, $request->user() answers as the target, so anything audited or attributed
 * here would lie about the actor. Applied to access administration (audit integrity), token minting
 * (a token would outlive the borrowed session) and credential mutations (passwords, two-factor,
 * identities - an impersonator must not be able to widen or keep a way into the account).
 */
readonly class EnsureNotImpersonating
{
    public function __construct(private ImpersonationService $impersonation)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->state($request) !== null) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.forbidden'),
                status: Response::HTTP_FORBIDDEN,
                detail: __('api.access.impersonation_blocked'),
            )->toResponse($request);
        }

        return $next($request);
    }
}
