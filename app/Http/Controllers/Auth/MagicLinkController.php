<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MagicLinkConsumeRequest;
use App\Http\Requests\Auth\MagicLinkSendRequest;
use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Auth\MagicLinkService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MagicLinkController extends Controller
{
    public function __construct(
        private readonly MagicLinkService $magicLinkService
    ) {
    }

    /**
     * Request a magic link for the given email.
     *
     * Always responds 202 with the same message, whether or not the email
     * belongs to a user - the send is enumeration-resistant end to end.
     */
    public function send(MagicLinkSendRequest $request): JsonResponse
    {
        $this->magicLinkService->send(
            email: (string) $request->validated('email'),
            redirect: $request->validated('redirect'),
        );

        return new JsonSuccessResponse(
            status: Response::HTTP_ACCEPTED,
            message: __('api.auth.magic_link.sent'),
            data: null,
        )->toResponse($request);
    }

    /**
     * Consume a magic-link token and establish the session.
     */
    public function consume(MagicLinkConsumeRequest $request): JsonResponse
    {
        $result = $this->magicLinkService->consume((string) $request->validated('token'));

        return new LoginResponse($result)->toResponse($request);
    }
}
