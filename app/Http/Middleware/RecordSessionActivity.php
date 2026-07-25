<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the session registry in sync with authenticated traffic.
 *
 * Runs after the response is produced so the recorded id is the final one -
 * login regenerates the session id mid-request, and recording earlier would
 * index a session that no longer exists. Skips guests, and stateless
 * (bearer-token) requests never carry a session at all. rescue()-wrapped:
 * bookkeeping must never be the reason a request fails.
 */
readonly class RecordSessionActivity
{
    public function __construct(private SessionRegistry $registry)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        rescue(fn() => $this->registry->record($request), report: true);

        return $response;
    }
}
