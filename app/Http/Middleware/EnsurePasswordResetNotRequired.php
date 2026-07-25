<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks accounts flagged with `require_password_reset` until they set a
 * new password.
 *
 * The flag is administrative (set from user management); once it is on,
 * the account may authenticate but nothing else, so a compromised or
 * stale password cannot keep operating the app. The two ways out stay
 * reachable by design: PUT /api/password is routed outside this gate, and
 * the forgot-password flow clears the flag as part of the reset. Runs
 * after auth:sanctum, so an unauthenticated request never reaches it.
 */
readonly class EnsurePasswordResetNotRequired
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) $request->user()?->require_password_reset) {
            return new JsonErrorResponse(
                title: __('api.auth.titles.password_reset_required'),
                status: Response::HTTP_FORBIDDEN,
                detail: __('api.auth.password_reset_required'),
            )->toResponse($request);
        }

        return $next($request);
    }
}
