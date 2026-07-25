<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PersonalAccessTokenStoreRequest;
use App\Http\Resources\PersonalAccessTokenResource;
use App\Http\Responses\JsonErrorResponse;
use App\Http\Responses\JsonSuccessResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the authenticated user's personal access tokens.
 *
 * All routes are session-only (EnsureSessionAuthenticated): tokens grant API
 * access but must never manage other tokens.
 */
class PersonalAccessTokenController extends Controller
{
    /**
     * List the user's tokens, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest('id')->get();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Tokens retrieved successfully',
            data: PersonalAccessTokenResource::collection($tokens),
        )->toResponse($request);
    }

    /**
     * Create a token and return its plaintext - once, in `meta.token`.
     *
     * The expiry is always explicit and capped by config; abilities default
     * to '*' (act fully as the user), and were validated against the user's
     * own permissions by the form request.
     */
    public function store(PersonalAccessTokenStoreRequest $request): JsonResponse
    {
        $expiresInDays = (int) ($request->validated('expires_in_days')
            ?? config('security.personal_access_tokens.default_lifetime_days', 30));

        $token = $request->user()->createToken(
            (string) $request->validated('name'),
            array_values($request->validated('abilities') ?? ['*']),
            now()->addDays($expiresInDays),
        );

        return new JsonSuccessResponse(
            status: Response::HTTP_CREATED,
            message: 'Token created successfully',
            data: PersonalAccessTokenResource::make($token->accessToken),
            meta: ['token' => $token->plainTextToken],
        )->toResponse($request);
    }

    /**
     * Revoke one of the user's own tokens.
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();

        if ($deleted === 0) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.not_found'),
                status: Response::HTTP_NOT_FOUND,
                detail: __('api.auth.tokens.not_found'),
            )->toResponse($request);
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_NO_CONTENT,
        )->toResponse($request);
    }
}
