<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standardized JSON success response for all API endpoints.
 *
 * Produces a consistent envelope:
 * ```json
 * {
 *   "status": 200,
 *   "message": "Success",
 *   "data": { ... },
 *   "meta": { ... }
 * }
 * ```
 * The `meta` key is omitted when empty. Passing `status: 204` skips the
 * envelope entirely and returns an empty body. Responses are pretty-printed
 * outside of production.
 */
class JsonSuccessResponse implements Responsable
{
    /**
     * Create a new JSON success response instance.
     *
     * @param  int  $status  HTTP status code. Use 204 for body-less responses.
     * @param  string  $message  Human-readable summary included in the envelope.
     * @param  JsonResource|array|null  $data  Payload. JsonResource instances are resolved before serialization.
     * @param  array  $meta  Optional metadata (e.g. pagination). Omitted when empty.
     * @param  array  $headers  Additional response headers. Override defaults including Content-Type.
     */
    public function __construct(
        protected int $status = Response::HTTP_OK,
        protected string $message = 'Success',
        protected JsonResource|array|null $data = [],
        protected array $meta = [],
        protected array $headers = []
    ) {
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * This method is automatically called by Laravel when the class is returned
     * from a controller or exception handler.
     *
     * @param  Request|null  $request  The current HTTP request instance.
     * @return JsonResponse
     */
    public function toResponse($request = null): JsonResponse
    {
        $request ??= request();

        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Content-Language' => app()->getLocale(),
        ];

        if ($this->status === Response::HTTP_NO_CONTENT) {
            return new JsonResponse(
                status: Response::HTTP_NO_CONTENT,
                headers: array_merge($defaultHeaders, $this->headers),
            );
        }

        $content = $this->data instanceof JsonResource
            ? $this->data->resolve($request)
            : $this->data;

        $payload = [
            'status' => $this->status,
            'message' => $this->message,
            'data' => $content,
        ];

        if (!empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }

        return new JsonResponse(
            data: $payload,
            status: $this->status,
            headers: array_merge($defaultHeaders, $this->headers),
            options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | (app()->isProduction() ? 0 : JSON_PRETTY_PRINT)
        );
    }
}
