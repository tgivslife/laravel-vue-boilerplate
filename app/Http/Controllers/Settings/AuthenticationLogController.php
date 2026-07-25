<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthenticationLogIndexRequest;
use App\Http\Responses\JsonSuccessResponse;
use App\Support\Auth\AuthenticationLogPage;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only view over the authenticated user's own authentication log.
 *
 * Unlike the sessions list this is history (retained for up to a year and pruned by auth:purge-authentication-logs),
 * so it pages instead of soft-capping. Session-only (EnsureSessionAuthenticated), like the rest
 * of the account-security surface.
 */
class AuthenticationLogController extends Controller
{
    /**
     * List the user's authentication log entries, newest first, optionally narrowed to a single day.
     *
     * Ordering, day-window semantics and the payload shape live in
     * AuthenticationLogPage, shared with the admin user detail page.
     */
    public function index(AuthenticationLogIndexRequest $request): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Authentication log retrieved successfully',
            data: AuthenticationLogPage::for($request->user(), $request->validated('date')),
        )->toResponse($request);
    }
}
