<?php

namespace App\Http\Responses;

use App\Http\Middleware\AttachRequestId;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standardized JSON API Error Response.
 *
 * This class implements the RFC 9457 (Problem Details for HTTP APIs) standard,
 * ensuring all API errors across the application follow a consistent,
 * machine-readable structure.
 *
 * Note: Obsoletes RFC 7807
 */
class JsonErrorResponse implements Responsable
{

    /**
     * Create a new JSON error response instance.
     *
     * @param  string  $title  A short, human-readable summary of the problem type.
     * @param  int  $status  The HTTP status code (defaults to 500).
     * @param  string|null  $type  A URI reference identifying the problem type. Defaults to "about:blank".
     * @param  string|null  $detail  A human-readable explanation specific to this occurrence.
     * @param  string|null  $instance  A URI reference identifying the specific occurrence (e.g., a Trace ID).
     * @param  array  $extra  Optional extension members (e.g., validation error details).
     */
    public function __construct(
        protected string $title,
        protected int $status = Response::HTTP_INTERNAL_SERVER_ERROR,
        protected ?string $type = 'about:blank',
        protected ?string $detail = null,
        protected ?string $instance = null,
        protected array $extra = []
    ) {
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * This method is automatically called by Laravel when the class is returned
     * from a controller or exception handler.
     *
     * When no `$instance` is provided, the request ID attached by
     * {@see AttachRequestId} is used automatically so every error response
     * is traceable in logs via `urn:uuid:<request-id>`.
     *
     * @param  Request|null  $request  The current HTTP request instance.
     * @return JsonResponse            A response with the 'application/problem+json' media type.
     */
    public function toResponse($request = null): JsonResponse
    {
        $request ??= request();

        $payload = array_filter([
            'type' => $this->type ?? 'about:blank',
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            ...$this->extra,
        ], fn($v) => $v !== null);

        if (!isset($payload['instance'])) {
            $requestId = $request->attributes->get(AttachRequestId::ATTRIBUTE);
            if ($requestId !== null) {
                $payload['instance'] = 'urn:uuid:'.$requestId;
            }
        }

        return new JsonResponse(
            data: $payload,
            status: $this->status,
            headers: [
                'Content-Type' => 'application/problem+json',
                'Content-Language' => app()->getLocale(),
            ],
            options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (app()->isProduction() ? 0 : JSON_PRETTY_PRINT)
        );
    }
}
