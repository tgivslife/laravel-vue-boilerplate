<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a unique request ID to every HTTP request/response cycle.
 *
 * Resolution order for the incoming ID:
 *  1. `X-Request-Id` header (primary)
 *  2. `X-Correlation-Id` header (fallback, common in AWS/Azure gateways)
 *  3. Generated time-ordered UUID when no valid candidate is found
 *
 * When `request.id.trust_proxy_only` is enabled in config, client-supplied IDs are accepted only for requests
 * that originate from a trusted proxy; all others receive a freshly generated ID regardless.
 *
 * The resolved ID is:
 *  - stored in `$request->attributes` under {@see AttachRequestId::ATTRIBUTE}
 *  - normalised back onto the request as the `X-Request-Id` header
 *  - injected into the Laravel log context so every log line carries it
 *  - echoed on the outgoing `X-Request-Id` response header
 *
 * Log context is flushed in a `finally` block so it does not leak into subsequent requests in long-running runtimes such as Laravel Octane.
 */
class AttachRequestId
{
    /** Response / request header used to carry the request ID. */
    public const HEADER = 'X-Request-Id';

    /**
     * Fallback header checked when {@see HEADER} is absent.
     * Common in AWS API Gateway, Azure APIM, and similar proxies.
     */
    public const FALLBACK_HEADER = 'X-Correlation-Id';

    /** Key under which the request ID is stored in `$request->attributes`. */
    public const ATTRIBUTE = 'request_id';

    /**
     * Handle an incoming request.
     *
     * Resolves the request ID, propagates it through the request attributes, request headers, and log context, then echoes it on the response header.
     * Log context is always flushed after the downstream handler returns.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure(Request): (Response)  $next  The next middleware/handler.
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        $request->headers->set(self::HEADER, $requestId);
        Log::withContext([self::ATTRIBUTE => $requestId]);

        try {
            $response = $next($request);
            $response->headers->set(self::HEADER, $requestId);

            return $response;
        } finally {
            // Flush shared log context so it does not bleed into the next
            // request when running under Octane or other persistent runtimes.
            Log::flushSharedContext();
        }
    }

    /**
     * Derive a request ID from the incoming headers, or generate a fresh one.
     *
     * When `request.id.trust_proxy_only` is enabled, a client-supplied ID is
     * accepted only if the request originates from a configured trusted proxy.
     *
     * @param  Request  $request
     * @return string  A validated client-supplied ID or a new time-ordered UUID.
     */
    private function resolveRequestId(Request $request): string
    {
        if (config('request.id.trust_proxy_only', false) && !$request->isFromTrustedProxy()) {
            return $this->generateId();
        }

        $candidate = (string) ($request->headers->get(self::HEADER)
            ?? $request->headers->get(self::FALLBACK_HEADER)
            ?? '');

        return $this->isValidCandidate($candidate) ? $candidate : $this->generateId();
    }

    /**
     * Determine whether a client-supplied candidate ID may be reused.
     *
     * A valid candidate must:
     *  - be non-empty after trimming
     *  - satisfy the configured character pattern
     *  - fall within the configured min/max length bounds
     *
     * @param  string  $candidate  Raw header value.
     * @return bool
     */
    private function isValidCandidate(string $candidate): bool
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return false;
        }

        $length = strlen($candidate);

        if ($length < config('request.id.min_length', 8) || $length > config('request.id.max_length', 128)) {
            return false;
        }

        return preg_match(config('request.id.pattern', '/^[A-Za-z0-9._-]+$/'), $candidate) === 1;
    }

    /**
     * Generate a new time-ordered UUID.
     *
     * Uses an ordered (time-sortable) UUID rather than a random UUID v4 so
     * that generated IDs can be sorted chronologically in logs and databases.
     *
     * @return string
     */
    private function generateId(): string
    {
        return (string) Str::orderedUuid();
    }
}
