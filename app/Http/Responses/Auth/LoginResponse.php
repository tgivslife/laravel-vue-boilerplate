<?php

namespace App\Http\Responses\Auth;

use App\Http\Resources\UserResource;
use App\Http\Responses\JsonErrorResponse;
use App\Http\Responses\JsonSuccessResponse;
use App\Support\Auth\LoginResult;
use App\Support\Auth\LoginStatus;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

readonly class LoginResponse implements Responsable
{
    public function __construct(private LoginResult $result)
    {
    }

    public function toResponse($request): JsonResponse
    {
        return match ($this->result->status) {
            LoginStatus::Success => $this->success(),

            LoginStatus::AlreadyAuthenticated => $this->alreadyAuthenticated(),
            LoginStatus::InvalidCredentials => $this->invalidCredentials(),
            LoginStatus::EmailNotVerified => $this->emailNotVerified(),
            LoginStatus::AccountDeactivated => $this->accountDeactivated(),
            LoginStatus::AccountLocked => $this->accountLocked(),
            LoginStatus::TwoFactorRequired => $this->twoFactorRequired(),
            LoginStatus::InvalidTwoFactorCode => $this->invalidTwoFactorCode(),
            LoginStatus::TwoFactorChallengeExpired => $this->twoFactorChallengeExpired(),
            LoginStatus::SessionUnavailable => $this->sessionUnavailable(),
            LoginStatus::InvalidMagicLink => $this->invalidMagicLink(),
        };
    }

    /**
     * The `provisioned` flag rides in `data` next to the user (like `two_factor_required`),
     * so the SPA can greet an account the login itself just created.
     */
    private function success(): JsonResponse
    {
        $data = UserResource::make($this->result->user)->resolve();

        if (is_array($this->result->extra) && ($this->result->extra['provisioned'] ?? false) === true) {
            $data['provisioned'] = true;
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Authentication successful',
            data: $data,
        )->toResponse();
    }

    private function alreadyAuthenticated(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.already_authenticated'),
            status: Response::HTTP_CONFLICT,
            detail: __('api.auth.already_authenticated')
        );
    }

    private function invalidCredentials(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.invalid_credentials'),
            status: Response::HTTP_UNAUTHORIZED,
            detail: __('api.auth.invalid_credentials')
        );
    }

    private function emailNotVerified(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.email_not_verified'),
            status: Response::HTTP_FORBIDDEN,
            detail: __('api.auth.email_not_verified')
        );
    }

    private function accountDeactivated(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.account_deactivated'),
            status: Response::HTTP_FORBIDDEN,
            detail: __('api.auth.account_deactivated')
        );
    }

    private function accountLocked(): JsonResponse
    {
        /** @var CarbonInterface $until */
        $until = $this->result->extra;

        $localizedUntil = $until->copy()
            ->setTimezone(config('app.timezone'))
            ->locale(app()->getLocale())
            ->isoFormat('LLL');

        return $this->error(
            title: __('api.auth.titles.account_locked'),
            status: Response::HTTP_LOCKED,
            detail: __('api.auth.account_locked', ['until' => $localizedUntil]),
            headers: ['Retry-After' => (string) max(0, (int) ceil($until->diffInSeconds(now(), true)))],
        );
    }

    /**
     * Not an error: the credentials verified and a challenge is parked in the session.
     * The flag rides in `data` (not meta) because the SPA's HttpClient unwraps responses to their data field.
     */
    private function twoFactorRequired(): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Two-factor authentication required',
            data: ['two_factor_required' => true],
        )->toResponse();
    }

    private function invalidTwoFactorCode(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.invalid_two_factor_code'),
            status: Response::HTTP_UNAUTHORIZED,
            detail: __('api.auth.invalid_two_factor_code')
        );
    }

    /**
     * 410 rather than 401 so the SPA can tell "sign in again" apart from "wrong code, try again".
     * The pending challenge only exists for a browser that already presented valid credentials, so the
     * distinction reveals nothing to a guesser.
     */
    private function twoFactorChallengeExpired(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.two_factor_challenge_expired'),
            status: Response::HTTP_GONE,
            detail: __('api.auth.two_factor_challenge_expired')
        );
    }

    private function invalidMagicLink(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.invalid_magic_link'),
            status: Response::HTTP_UNAUTHORIZED,
            detail: __('api.auth.invalid_magic_link')
        );
    }

    private function sessionUnavailable(): JsonResponse
    {
        return $this->error(
            title: __('api.auth.titles.session_unavailable'),
            status: Response::HTTP_BAD_REQUEST,
            detail: __('api.auth.session_unavailable')
        );
    }

    private function error(string $title, int $status, string $detail, array $headers = []): JsonResponse
    {
        $response = new JsonErrorResponse(
            title: $title,
            status: $status,
            detail: $detail
        )->toResponse();

        if (!empty($headers)) {
            $response->withHeaders($headers);
        }

        return $response;
    }
}
