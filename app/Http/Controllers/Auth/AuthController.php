<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\AuthServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Payloads\Auth\LoginPayload;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\JsonSuccessResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthServiceContract $authService
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        // Disabled like an unconfigured identity provider: the door does
        // not exist (the login page hides the form via /api/auth/methods).
        abort_unless((bool) config('security.password_login.enabled', true), Response::HTTP_NOT_FOUND);

        $payload = LoginPayload::fromRequest($request);
        $result = $this->authService->login($payload);

        return new LoginResponse($result)->toResponse($request);
    }

    public function user(Request $request): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'User retrieved successfully',
            data: AuthenticatedUserResource::make($request->user()),
        )->toResponse($request);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return new JsonSuccessResponse(
            status: Response::HTTP_NO_CONTENT,
        )->toResponse($request);
    }
}
