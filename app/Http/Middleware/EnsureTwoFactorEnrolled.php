<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonErrorResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks accounts flagged `two_factor_required` until they enroll.
 *
 * The flag is administrative (set from user management); once it is on and no confirmed enrollment exists,
 * the account may authenticate but nothing else, mirroring the forced-password-reset gate.
 * The way out stays reachable by design: the two-factor enrollment endpoints are routed outside this gate.
 * Runs after auth:sanctum, so an unauthenticated request never reaches it.
 */
readonly class EnsureTwoFactorEnrolled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->mustEnrollTwoFactor()) {
            return new JsonErrorResponse(
                title: __('api.auth.titles.two_factor_enrollment_required'),
                status: Response::HTTP_FORBIDDEN,
                detail: __('api.auth.two_factor_enrollment_required'),
            )->toResponse($request);
        }

        return $next($request);
    }
}
