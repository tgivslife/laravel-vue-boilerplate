<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PreferencesUpdateRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Http\Responses\JsonSuccessResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the authenticated user's own preferences (locale, theme, etc).
 *
 * Session-only (EnsureSessionAuthenticated), like the rest of the settings surface.
 * Partial updates: only the submitted keys change, the rest keep their stored value or registry default.
 */
class PreferencesController extends Controller
{
    /**
     * Store the submitted preference values.
     */
    public function update(PreferencesUpdateRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'preferences' => [...(array) $user->preferences, ...$request->validated()],
        ])->save();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.preferences.updated'),
            data: AuthenticatedUserResource::make($user),
        )->toResponse($request);
    }
}
