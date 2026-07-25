<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Http\Responses\JsonErrorResponse;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService
    ) {
    }

    /**
     * Email a password-reset link to the given address.
     *
     * Always responds 202 with the same message, whether or not the email
     * belongs to a user - the send is enumeration-resistant end to end.
     */
    public function send(PasswordResetLinkRequest $request): JsonResponse
    {
        $this->passwordResetService->sendResetLink((string) $request->validated('email'));

        return new JsonSuccessResponse(
            status: Response::HTTP_ACCEPTED,
            message: __('api.auth.password_reset.sent'),
            data: null,
        )->toResponse($request);
    }

    /**
     * Reset the password using an emailed token.
     */
    public function reset(PasswordResetRequest $request): JsonResponse
    {
        $wasReset = $this->passwordResetService->reset($request->validated());

        if (!$wasReset) {
            return new JsonErrorResponse(
                title: __('api.auth.titles.invalid_password_reset'),
                status: Response::HTTP_UNAUTHORIZED,
                detail: __('api.auth.invalid_password_reset'),
            )->toResponse($request);
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.auth.password_reset.success'),
            data: null,
        )->toResponse($request);
    }
}
