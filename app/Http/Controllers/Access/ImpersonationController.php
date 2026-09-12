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
 * store() sits with the access administration (session-only, users.impersonate, behind the feature switch).
 * destroy() is an escape hatch outside that group, the forced-reset and two-factor gates, and the switch: mid-impersonation
 * the authenticated user is the target, who holds no permission and may be trapped by those gates, and the session marker
 * is proof the feature was on when the swap happened - flipping it off never strands a live impersonation.
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
     * End the swap and return the restored actor's bootstrap payload.
     *
     * An actor retired or with changed credentials is cut off by EnsureUserCanAuthenticate before this runs, so such a session answers 401 here.
     * The null payload for an unrestorable actor is the service's own defense in depth, reachable only if the cutoff did not run first.
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
