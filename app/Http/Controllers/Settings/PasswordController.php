<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Auth\SessionRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the authenticated user change (or first set) their own password.
 *
 * Session-only (EnsureSessionAuthenticated) and rate limited by the
 * password-confirm limiter, since the request verifies the current
 * password for accounts that have one.
 */
class PasswordController extends Controller
{
    public function __construct(
        private readonly SessionRegistry $sessionRegistry
    ) {
    }

    /**
     * Update the user's password.
     *
     * A password change prompted by a suspected leak must not leave the
     * attacker signed in: unless the user opts out, every other session
     * row is deleted, and the remember token is always rotated so
     * remembered browsers cannot silently mint fresh sessions either.
     * The current session stays signed in.
     */
    public function update(PasswordUpdateRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make((string) $request->validated('password')),
            'password_changed_at' => now(),
            // A change satisfies an admin-imposed forced reset.
            'require_password_reset' => false,
        ])->setRememberToken(Str::random(60));

        $user->save();

        if ((bool) ($request->validated('revoke_other_sessions') ?? true)) {
            $this->sessionRegistry->destroyOthers($user, $request->session()->getId());
        }

        /*
         * Fired for the settings path too, so a single listener (SendPasswordChangedNotification) mails the owner about
         * every password change, whichever door it came through.
         */
        event(new PasswordReset($user));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.password.updated'),
            data: null,
        )->toResponse($request);
    }
}
