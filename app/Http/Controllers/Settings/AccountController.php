<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccountDeleteRequest;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Access\AccessAuditor;
use App\Services\Access\AccountRetirementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service deletion of the authenticated user's own account.
 *
 * Session-only (EnsureSessionAuthenticated) and confirmed by the form request (current password, or the typed email for passwordless accounts).
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountRetirementService $retirement,
        private readonly AccessAuditor $auditor,
    ) {
    }

    /**
     * Retire the account (AccountRetirementService: credentials severed, email tombstoned and hash  kept for membership
     * lookups, row soft-deleted - the same mechanics as the admin delete), then end the browser session that asked for it.
     * Audited as `user.self_deleted` with the owner as actor - removing every way into an account belongs in the trail
     * no matter who did it - and the before-snapshot retains the original address, like the admin delete does.
     */
    public function destroy(AccountDeleteRequest $request): JsonResponse
    {
        $user = $request->user();

        $before = [
            'email' => $user->email,
            'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
        ];

        DB::transaction(function () use ($user, $before): void {
            $this->retirement->retire($user);

            $this->auditor->record($user, 'user.self_deleted', $user, $before, null);
        });

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
