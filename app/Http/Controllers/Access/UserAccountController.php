<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\UserAccountUpdateRequest;
use App\Http\Requests\AuthenticationLogIndexRequest;
use App\Http\Resources\Access\AccessAuditLogResource;
use App\Http\Resources\Access\AccessUserResource;
use App\Http\Resources\SessionResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\Access\AccessAuditLog;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Auth\SessionRegistry;
use App\Support\Auth\AuthenticationLogPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account management for the admin user detail page: profile facts, account deletion, and the read-only security surface
 * (live sessions and authentication history, the admin's view of what Settings shows the user  about themselves).
 * Mutations go through AccessControlService (lockout guards + audit).
 */
class UserAccountController extends Controller
{
    public function __construct(
        private readonly AccessControlService $accessControl,
        private readonly SessionRegistry $sessionRegistry
    ) {
    }

    /**
     * Patch the account facts present in the request: name, email verification, forced password reset.
     */
    public function update(UserAccountUpdateRequest $request, User $user): JsonResponse
    {
        $this->accessControl->updateUserAccount($request->user(), $user, $request->validated());

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.account_updated'),
            data: ['user' => new AccessUserResource($user->refresh(), detailed: true)],
        )->toResponse($request);
    }

    /**
     * Generate a temporary password and force a reset: the user signs in with the communicated password and
     * must change it before doing anything else.
     * The plaintext is returned exactly once, for the admin to pass on.
     */
    public function forcePasswordReset(Request $request, User $user): JsonResponse
    {
        $temporaryPassword = $this->accessControl->forcePasswordReset($request->user(), $user);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.password_reset_forced'),
            data: [
                'user' => new AccessUserResource($user->refresh(), detailed: true),
                'temporary_password' => $temporaryPassword,
            ],
        )->toResponse($request);
    }

    /**
     * Re-mail a pending invitation's first-sign-in link, revoking the previous one.
     * Refused for accounts that were already entered (AccessControlService::resendInvitation()).
     */
    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        // Disabled like the self-service endpoints: the door does not exist.
        abort_unless((bool) config('security.invitations.enabled', true), Response::HTTP_NOT_FOUND);

        $this->accessControl->resendInvitation($request->user(), $user);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.invitation_sent'),
            data: ['user' => new AccessUserResource($user->refresh(), detailed: true)],
        )->toResponse($request);
    }

    /**
     * Clear the target's two-factor enrollment, for when the authenticator is lost.
     * The user keeps signing in with their remaining factors and can re-enroll; if the enrollment mandate flag is on,
     * the gate forces them to. The owner is notified by mail.
     */
    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        // Disabled like the self-service endpoints: the door does not exist.
        abort_unless((bool) config('security.two_factor.enabled', true), Response::HTTP_NOT_FOUND);

        $this->accessControl->resetTwoFactor($request->user(), $user);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.two_factor_reset'),
            data: ['user' => new AccessUserResource($user->refresh(), detailed: true)],
        )->toResponse($request);
    }

    /**
     * Soft-delete the account (never your own; never the last manager).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->accessControl->deleteUser($request->user(), $user);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.user_deleted'),
            data: null,
        )->toResponse($request);
    }

    /**
     * The target user's live sessions, most recently active first, in the same shape as the Settings sessions list.
     * `is_current` still marks the requester's own session so admins viewing themselves see it.
     */
    public function sessions(Request $request, User $user): JsonResponse
    {
        $live = $this->sessionRegistry->forUser($user);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Sessions retrieved successfully',
            data: [
                'sessions' => SessionResource::collection(
                    $live->take((int) config('security.session_registry.display_limit', 50))->values()
                ),
                'total' => $live->count(),
            ],
        )->toResponse($request);
    }

    /**
     * The target user's authentication log, newest first, optionally narrowed to a single day
     * - the admin's view of the Settings log, shared through AuthenticationLogPage.
     */
    public function authenticationLogs(AuthenticationLogIndexRequest $request, User $user): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Authentication log retrieved successfully',
            data: AuthenticationLogPage::for($user, $request->validated('date')),
        )->toResponse($request);
    }

    /**
     * The access audit trail with the target user as subject, newest first: every admin mutation of this
     * account (profile facts, state changes, grant syncs) with its actor and scalar before/after snapshots.
     */
    public function auditLogs(Request $request, User $user): JsonResponse
    {
        $entries = AccessAuditLog::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->with('actor:id,first_name,last_name,email,deleted_at')
            ->orderByDesc('id')
            ->simplePaginate((int) config('access.audit_log.page_size', 15));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Audit log retrieved successfully',
            data: [
                'entries' => AccessAuditLogResource::collection($entries->items()),
                'has_more' => $entries->hasMorePages(),
            ],
        )->toResponse($request);
    }
}
