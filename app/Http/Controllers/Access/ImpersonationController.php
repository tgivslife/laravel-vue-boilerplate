<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\User;
use App\Services\Access\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin impersonation: enter a user's account, leave it again.
 *
 * start() sits with the access administration (session-only, users.impersonate);
 * stop() is routed as an escape hatch outside the forced-reset and two-factor gates, because mid-impersonation the
 * authenticated user is the target - who may be trapped by those gates and does not hold the permission.
 *
 * Only start() is gated by the feature switch: with it off the door in does not exist (404), but a session marker is
 * proof the feature was on when the swap happened, so the way out honors it regardless, flipping the switch off never
 * strands a live impersonation.
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation)
    {
    }

    /**
     * Swap the session's identity to the target and return the target's bootstrap payload.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        abort_unless((bool) config('access.impersonation.enabled', false), Response::HTTP_NOT_FOUND);

        $this->impersonation->start($request->user(), $user, $request);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.impersonation_started'),
            data: AuthenticatedUserResource::make($user),
        )->toResponse($request);
    }

    /**
     * End the swap. Returns the restored actor's bootstrap payload, or null user data when the
     * actor could not be restored (deactivated, banned or deleted mid-impersonation) and the
     * session was destroyed instead.
     */
    public function destroy(Request $request): JsonResponse
    {
        $actor = $this->impersonation->stop($request);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.impersonation_ended'),
            data: $actor === null ? null : AuthenticatedUserResource::make($actor),
        )->toResponse($request);
    }
}
