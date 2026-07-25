<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to session-authenticated (first-party) requests.
 *
 * Runs after `auth:sanctum`, which marks session-authenticated users with a
 * TransientToken and bearer requests with their PersonalAccessToken.
 * Applied to the token-management endpoints so a leaked token cannot mint
 * replacements for itself or revoke others - token lifecycle changes must come from a browser session.
 */
readonly class EnsureSessionAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!($request->user()?->currentAccessToken() instanceof TransientToken)) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.forbidden'),
                status: Response::HTTP_FORBIDDEN,
                detail: __('api.auth.tokens.session_required'),
            )->toResponse($request);
        }

        return $next($request);
    }
}
