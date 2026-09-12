<?php

namespace App\Http\Middleware;

use App\Contracts\AuthServiceContract;
use App\Http\Responses\JsonErrorResponse;
use App\Models\User;
use App\Services\Access\ImpersonationService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cuts off authenticated requests whose account, or whose impersonating admin, can no longer authenticate.
 *
 * Login-time checks only gate NEW sessions; without this, a user deactivated or banned mid-session would keep their access until the session or token expired.
 * Runs after `auth:sanctum`, revokes whichever credential authenticated the request (session or personal access token), and responds 403 with the same problem shape the login endpoints use.
 * A borrowed session is held to its admin as well: retired or re-credentialed, the session is torn down and the request answers 401.
 */
readonly class EnsureUserCanAuthenticate
{
    public function __construct(private AuthServiceContract $authService, private ImpersonationService $impersonation)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException When the impersonating actor no longer vouches for the borrowed session.
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

        if ($user instanceof User && $this->impersonation->cutOffUnvouchedActor($request)) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
