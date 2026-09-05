<?php

namespace App\Http\Middleware;

use App\Contracts\CaptchaVerifier;
use App\Http\Responses\JsonErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The anti-abuse gate for the public doors (login, magic-link request, forgot-password).
 *
 * `security.captcha.enabled` plus the per-door list in `security.captcha.doors` switch it on.
 * The route names its door (`RequireCaptcha::class.':login'`), the request carries the widget's token
 * as `captcha_token`, and the bound CaptchaVerifier decides.
 * Runs before the controller and answers identically whatever the account state, so it adds no
 * enumeration surface on doors built to be enumeration-resistant.
 */
readonly class RequireCaptcha
{
    public function __construct(
        private CaptchaVerifier $verifier,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $door): Response
    {
        if (!$this->guards($door)) {
            return $next($request);
        }

        $token = (string) $request->input('captcha_token', '');

        if ($token === '' || !$this->verifier->verify($token, $request->ip())) {
            return new JsonErrorResponse(
                title: __('api.auth.titles.captcha_failed'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                detail: __('api.auth.captcha_failed'),
            )->toResponse($request);
        }

        return $next($request);
    }

    protected function guards(string $door): bool
    {
        return (bool) config('security.captcha.enabled', false)
            && in_array($door, (array) config('security.captcha.doors', []), true)
            && $this->doorIsOpen($door);
    }

    /**
     * A switched-off door needs no anti-abuse gate: the request can do nothing (the login
     * controller 404s, the magic-link and reset services no-op), so verifying would burn a
     * vendor round-trip for it - and answering 422 "captcha failed" on the disabled login
     * door would reveal an endpoint whose feature switch promises a plain 404.
     */
    private function doorIsOpen(string $door): bool
    {
        return (bool) match ($door) {
            'login' => config('security.password_login.enabled', true),
            'magic_link' => config('security.magic_link.enabled', true),
            'password_reset' => config('security.password_reset.enabled', true),
            default => true,
        };
    }
}
