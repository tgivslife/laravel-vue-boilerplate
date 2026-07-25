<?php

namespace App\Http\Middleware;

use App\Contracts\AuthServiceContract;
use App\Http\Responses\JsonErrorResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cuts off authenticated requests whose account can no longer authenticate.
 *
 * Login-time checks only gate NEW sessions; without this, a user deactivated
 * or banned mid-session would keep their access until the session or token expired.
 * Runs after `auth:sanctum`, revokes whichever credential authenticated the request
 * (session or personal access token), and responds 403 with the same problem shape the login endpoints use.
 */
readonly class EnsureUserCanAuthenticate
{
    public function __construct(private AuthServiceContract $authService)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && !$user->canAuthenticate()) {
            $this->authService->logout($request);

            return new JsonErrorResponse(
                title: __('api.auth.titles.account_deactivated'),
                status: Response::HTTP_FORBIDDEN,
                detail: __('api.auth.account_deactivated'),
            )->toResponse($request);
        }

        return $next($request);
    }
}
