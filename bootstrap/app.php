<?php

use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\EnsureJsonApiRequest;
use App\Http\Middleware\RecordSessionActivity;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Responses\JsonErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware
            ->statefulApi()
            ->throttleApi()
            // The allowed proxies/hosts are supplied in SecurityServiceProvider::boot().
            ->trustProxies()
            ->trustHosts();

        /*
         * Response security headers (HSTS, CSP, baseline hardening) on every
         * response - SPA shell, API and health probe alike. Appended to the
         * global stack so it wraps the groups: group middleware that chose a
         * stricter baseline value (EnsureJsonApiRequest) keeps it.
         */
        $middleware->append(SetSecurityHeaders::class);

        $middleware->prependToGroup('api', EnsureJsonApiRequest::class);
        $middleware->prependToGroup('api', SetRequestLocale::class);
        $middleware->prependToGroup('api', AttachRequestId::class);

        /*
         * Keeps the user-session registry in sync so sessions can be listed
         * and revoked on any session driver. Appended so it runs inside the
         * session/auth stack (statefulApi for api, StartSession for web).
         */
        $middleware->appendToGroup('web', RecordSessionActivity::class);
        $middleware->appendToGroup('api', RecordSessionActivity::class);

        /*
         * Sanctum's token-ability gates, for routes that require a token scoped with a given permission name.
         * Session requests carry a TransientToken and always pass; abilities can only narrow access.
         */
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->wantsJson();
        });

        /*
         * Every renderer returns a materialized response (->toResponse($request)), never the bare Responsable:
         * the fatal-error shutdown path (HandleExceptions::renderHttpResponse) calls ->send() directly on whatever a
         * renderer returns, bypassing the pipeline that would normally convert a Responsable - a bare JsonErrorResponse
         * there is a secondary fatal ("Call to undefined method JsonErrorResponse::send()") masking the original error.
         */
        $exceptions->render(static function (NotFoundHttpException $e, Request $request) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.not_found'),
                status: $e->getStatusCode(),
                detail: $e->getMessage() ?: __('api.errors.http.not_found')
            )->toResponse($request);
        });

        $exceptions->render(static function (AuthenticationException $e, Request $request) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.unauthorized'),
                status: Response::HTTP_UNAUTHORIZED,
                detail: $e->getMessage() ?: __('api.errors.http.unauthorized')
            )->toResponse($request);
        });

        $exceptions->render(static function (AuthorizationException $e, Request $request) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.forbidden'),
                status: Response::HTTP_FORBIDDEN,
                detail: $e->getMessage() ?: __('api.errors.http.forbidden')
            )->toResponse($request);
        });

        $exceptions->render(static function (AccessDeniedHttpException $e, Request $request) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.forbidden'),
                status: Response::HTTP_FORBIDDEN,
                detail: $e->getMessage() ?: __('api.errors.http.forbidden_access')
            )->toResponse($request);
        });

        $exceptions->render(static function (TooManyRequestsHttpException $e, Request $request) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            $response = new JsonErrorResponse(
                title: __('api.errors.titles.too_many_requests'),
                status: $e->getStatusCode(),
                detail: $e->getMessage() ?: __('api.errors.http.too_many_requests', ['seconds' => $retryAfter])
            )->toResponse($request);

            if ($retryAfter) {
                $response->headers->set('Retry-After', $retryAfter);
            }

            return $response;
        });

        $exceptions->render(static function (ValidationException $e, Request $request) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = [
                        'name' => $field,
                        'detail' => $message,
                    ];
                }
            }

            return new JsonErrorResponse(
                title: __('api.errors.titles.validation_failed'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                detail: __('api.errors.validation.failed'),
                extra: ['errors' => $errors]
            )->toResponse($request);
        });

        $exceptions->render(static function (Throwable $e, Request $request) {
            if (!($request->is('api/*') || $request->wantsJson())) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            // Laravel's exception handler rewrites TokenMismatchException into a bare HttpException(419, ...)
            // before it reaches here, so the CSRF case can only be recognized by status code, not by exception type.
            [$title, $detail] = match (true) {
                $e instanceof MethodNotAllowedHttpException => [
                    __('api.errors.titles.method_not_allowed'),
                    __('api.errors.http.method_not_allowed', ['method' => $request->method()]),
                ],
                $status === 419 => [
                    __('api.errors.titles.page_expired'),
                    __('api.errors.http.page_expired'),
                ],
                default => [
                    __('api.errors.titles.internal_server_error'),
                    __('api.errors.http.internal_server_error'),
                ],
            };

            return new JsonErrorResponse(
                title: $title,
                status: $status,
                detail: $detail
            )->toResponse($request);
        });
    })->create();
