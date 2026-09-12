<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps the session registry in step with authenticated traffic.
 *
 * Runs after the response is produced so the recorded id is the final one - login regenerates the session id mid-request,
 * and registering earlier would index a session that no longer exists.
 * Skips guests, and stateless (bearer-token) requests never carry a session at all.
 *
 * Registration is not optional: a session the registry could not take would be beyond every revocation, so it is signed out before the failure is rendered.
 * Rendering alone would not do - the session is saved after this middleware returns, signed in, error response or not.
 * The activity refresh stays best-effort inside the registry.
 */
readonly class RegisterSession
{
    public function __construct(private SessionRegistry $registry)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws Throwable When the session could not be registered; it has been signed out by then.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->registry->register($request);
        } catch (Throwable $exception) {
            $this->registry->signOut($request);

            throw $exception;
        }

        return $response;
    }
}
