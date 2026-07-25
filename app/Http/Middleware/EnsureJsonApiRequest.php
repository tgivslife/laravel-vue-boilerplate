<?php

namespace App\Http\Middleware;

use App\Http\Responses\JsonErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure all API requests are handled as JSON.
 *
 * This middleware enforces JSON responses by setting the 'Accept' header,
 * validates that request payloads are JSON-encoded, and adds baseline
 * security headers to the resulting response.
 */
class EnsureJsonApiRequest
{
    /**
     * Handle an incoming request.
     *
     * Forces the request into a JSON context to ensure consistent error rendering,
     * checks for valid JSON payloads on write operations, and appends security headers.
     *
     * @param  Request  $request  The incoming HTTP request instance.
     * @param  Closure(Request): (Response)  $next  The next middleware in the pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force API exception rendering down the JSON path, even when clients omit Accept.
        $request->headers->set('Accept', 'application/json');

        if ($this->hasRequestPayload($request) && !$request->isJson()) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.unsupported_media_type'),
                status: Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                detail: __('api.errors.http.unsupported_media_type'),
            )->toResponse($request);
        }

        $response = $next($request);

        // Baseline response hardening headers for API traffic.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Determine if the current request contains a body payload.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return bool True if the request method allows a body and a payload is detected.
     */
    private function hasRequestPayload(Request $request): bool
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        return $request->getContent() !== '' || $request->request->count() > 0;
    }
}
