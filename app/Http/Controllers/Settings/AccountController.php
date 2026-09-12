<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccountDeleteRequest;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Access\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service deletion of the authenticated user's own account.
 *
 * Session-only (EnsureSessionAuthenticated) and confirmed by the form request (current password, or the typed email for passwordless accounts).
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccessControlService $accessControl)
    {
    }

    /**
     * Retire the account through the guarded access transaction (AccessControlService::deleteOwnAccount: the same
     * mechanics as the admin delete, audited as user.self_deleted with the owner as actor, refused for the last active
     * holder of a lockout permission), then end the browser session that asked for it.
     */
    public function destroy(AccountDeleteRequest $request): JsonResponse
    {
        $this->accessControl->deleteOwnAccount($request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.account.deleted'),
            data: null,
        )->toResponse($request);
    }
}
