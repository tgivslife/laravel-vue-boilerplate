<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Http\Responses\JsonSuccessResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the authenticated user's own profile.
 *
 * Session-only (EnsureSessionAuthenticated): profile changes must come from
 * a browser session, never from an API token.
 */
class ProfileController extends Controller
{
    /**
     * Update the user's display name.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->fill($request->validated())->save();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.profile.updated'),
            data: AuthenticatedUserResource::make($user),
        )->toResponse($request);
    }
}
