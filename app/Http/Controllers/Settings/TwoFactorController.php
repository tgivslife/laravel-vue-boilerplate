<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TwoFactorConfirmRequest;
use App\Http\Requests\Settings\TwoFactorDisableRequest;
use App\Http\Requests\Settings\TwoFactorEnrollRequest;
use App\Http\Requests\Settings\TwoFactorRecoveryCodesRequest;
use App\Http\Responses\JsonErrorResponse;
use App\Http\Responses\JsonSuccessResponse;
use App\Notifications\TwoFactorDisabledNotification;
use App\Notifications\TwoFactorEnabledNotification;
use App\Services\Access\AccessAuditor;
use App\Services\Auth\TwoFactorService;
use App\Support\Device;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service TOTP enrollment for the authenticated user's own account.
 *
 * Session-only (EnsureSessionAuthenticated): a leaked API token must not be able to change the account's second factor.
 * Recovery codes appear in plaintext exactly once, in the response that mints them.
 *
 * With the feature switched off, every endpoint here is a 404 - the door does not exist, like disabled password login
 * (the admin reset is gated the same way).
 * Existing enrollments become inert - login never challenges - and reappear untouched when the feature returns.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AccessAuditor $auditor,
    ) {
    }

    /**
     * Start enrollment: mint an unconfirmed secret and return what the authenticator setup screen needs.
     * 409 while the factor is active - it must be disabled first, so a working factor cannot be silently replaced.
     */
    public function store(TwoFactorEnrollRequest $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $enrollment = $this->twoFactor->startEnrollment($request->user());

        if ($enrollment === null) {
            return new JsonErrorResponse(
                title: __('api.settings.two_factor.titles.already_enabled'),
                status: Response::HTTP_CONFLICT,
                detail: __('api.settings.two_factor.already_enabled'),
            )->toResponse();
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Two-factor enrollment started',
            data: [
                'secret' => $enrollment->secret,
                'otpauth_url' => $enrollment->otpauthUrl,
                'qr_svg' => $enrollment->qrSvg,
            ],
        )->toResponse($request);
    }

    /**
     * Activate the pending secret with a working code;
     * The recovery codes ride back in this response and are never shown again.
     */
    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $codes = $this->twoFactor->confirmEnrollment($request->user(), $request->validated('code'));

        if ($codes === null) {
            return new JsonErrorResponse(
                title: __('api.settings.two_factor.titles.invalid_code'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                detail: __('api.settings.two_factor.invalid_code'),
            )->toResponse();
        }

        // Self-service security event: the actor is the account owner.
        $this->auditor->record(
            $request->user(),
            'user.two_factor_enabled',
            $request->user(),
            ['two_factor_enabled' => false],
            ['two_factor_enabled' => true],
        );

        if ((bool) config('security.two_factor.change_notification.enabled', true)) {
            $request->user()->notify(
                new TwoFactorEnabledNotification(
                    deviceName: Device::name($request),
                    ipAddress: $request->ip(),
                    changedAt: now(),
                )->locale(app()->getLocale())
            );
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.two_factor.enabled'),
            data: ['recovery_codes' => $codes],
        )->toResponse($request);
    }

    /**
     * Disable the factor, clearing the secret and recovery codes.
     * Also discards a pending (unconfirmed) enrollment, so it doubles as the wizard's cancel.
     */
    public function destroy(TwoFactorDisableRequest $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        // Discarding a never-confirmed setup is not a security event; only the loss of an active factor is worth a mail.
        $wasActive = $request->user()->hasTwoFactorEnabled();

        $this->twoFactor->disable($request->user());

        if ($wasActive) {
            $this->auditor->record(
                $request->user(),
                'user.two_factor_disabled',
                $request->user(),
                ['two_factor_enabled' => true],
                ['two_factor_enabled' => false],
            );
        }

        if ($wasActive && (bool) config('security.two_factor.change_notification.enabled', true)) {
            $request->user()->notify(
                new TwoFactorDisabledNotification(
                    byAdministrator: false,
                    deviceName: Device::name($request),
                    ipAddress: $request->ip(),
                    changedAt: now(),
                )->locale(app()->getLocale())
            );
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.two_factor.disabled'),
            data: null,
        )->toResponse($request);
    }

    /**
     * Replace the recovery codes with a fresh set, invalidating every outstanding one.
     */
    public function recoveryCodes(TwoFactorRecoveryCodesRequest $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        if ($codes === null) {
            return new JsonErrorResponse(
                title: __('api.settings.two_factor.titles.not_enabled'),
                status: Response::HTTP_CONFLICT,
                detail: __('api.settings.two_factor.not_enabled'),
            )->toResponse();
        }

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.two_factor.codes_regenerated'),
            data: ['recovery_codes' => $codes],
        )->toResponse($request);
    }

    /**
     * Disabled like password login: the door does not exist. The SPA hides the surface via the user resource's `two_factor_available` flag.
     */
    private function assertFeatureEnabled(): void
    {
        abort_unless((bool) config('security.two_factor.enabled', true), Response::HTTP_NOT_FOUND);
    }
}
