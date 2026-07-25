<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\IdentityUnlinkRequest;
use App\Http\Responses\JsonErrorResponse;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Access\AccessAuditor;
use App\Services\Auth\IdentityProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authenticated user's connected external identities.
 *
 * Listing covers every configured provider (linked or not, available or not) so the settings page can render the full picture;
 * Linking itself happens through the browser OIDC flow (IdentityProviderController's `connect` intent), never through JSON.
 * Session-only (EnsureSessionAuthenticated), like the rest of the account-security surface.
 *
 * With the master switch off, both endpoints are a 404 - the door does not exist, like disabled password login.
 * Existing links become inert and reappear untouched when the feature returns.
 */
class IdentityController extends Controller
{
    public function __construct(
        private readonly IdentityProviderRegistry $registry,
        private readonly AccessAuditor $auditor,
    ) {
    }

    /**
     * List every configured provider with its link state.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $linked = $request->user()->identities()->get()->keyBy('provider');

        $identities = array_map(fn(string $provider): array => [
            'provider' => $provider,
            'available' => $this->registry->enabled($provider),
            'linked' => $linked->has($provider),
            'linked_at' => $linked->get($provider)?->created_at?->toISOString(),
            'last_used_at' => $linked->get($provider)?->last_used_at?->toISOString(),
        ], $this->registry->providers());

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Identities retrieved successfully',
            data: ['identities' => $identities],
        )->toResponse($request);
    }

    /**
     * Disconnect a linked identity.
     */
    public function destroy(IdentityUnlinkRequest $request, string $provider): JsonResponse
    {
        $this->assertFeatureEnabled();

        $deleted = $request->user()->identities()
            ->where('provider', $provider)
            ->delete();

        if ($deleted === 0) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.not_found'),
                status: Response::HTTP_NOT_FOUND,
                detail: __('api.settings.identities.not_linked'),
            )->toResponse($request);
        }

        // Self-service security event: a way into the account was removed.
        $this->auditor->record(
            $request->user(),
            'user.identity_unlinked',
            $request->user(),
            ['provider' => $provider],
            null,
        );

        return new JsonSuccessResponse(
            status: Response::HTTP_NO_CONTENT,
        )->toResponse($request);
    }

    /**
     * Disabled like password login: the door does not exist.
     * The SPA hides the surface via the user resource's `identity_providers_available` flag.
     */
    private function assertFeatureEnabled(): void
    {
        abort_unless((bool) config('security.identity_providers.enabled', true), Response::HTTP_NOT_FOUND);
    }
}
