<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Responses\Auth\LoginResponse;
use App\Services\Auth\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Completes a two-factor login parked by TwoFactorChallengeService.
 *
 * Deliberately not throttled by route middleware: the pending challenge only exists behind verified credentials,
 * and failed codes feed the same lockout as failed passwords - mirroring how POST /api/login is guarded.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorChallengeService $challenges
    ) {
    }

    /**
     * Answer the pending challenge with a TOTP or recovery code.
     */
    public function challenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        // Disabled like password login: the door does not exist, and no
        // challenge can be pending because login stops stashing them.
        abort_unless((bool) config('security.two_factor.enabled', true), Response::HTTP_NOT_FOUND);

        $result = $this->challenges->challenge(
            code: $request->validated('code'),
            recoveryCode: $request->validated('recovery_code'),
        );

        return new LoginResponse($result)->toResponse($request);
    }
}
