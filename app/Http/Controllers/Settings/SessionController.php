<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SessionsDestroyOthersRequest;
use App\Http\Resources\SessionResource;
use App\Http\Responses\JsonErrorResponse;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\User;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the authenticated user's browser sessions.
 *
 * Backed by the SessionRegistry, so listing and revocation work on any session driver (database, redis, ...).
 * Raw session ids never leave the server: rows are addressed by a SHA-256 digest of the id, so a listed
 * session can be revoked but never hijacked. All routes are session-only (EnsureSessionAuthenticated).
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly SessionRegistry $sessionRegistry
    ) {
    }

    /**
     * List the user's live sessions, most recently active first.
     *
     * Rendering is soft-capped (`session_registry.display_limit`): `total` lets the client say how much was held back.
     * Uncapped sessions are still revocable in bulk via destroyOthers().
     */
    public function index(Request $request): JsonResponse
    {
        $live = $this->sessionRegistry->forUser($request->user());

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
     * Sign out every session except the current one.
     *
     * The remember token is rotated first, so a recaller arriving between the two steps meets a dead token: remembered
     * browsers would otherwise mint a fresh session.
     * The current browser loses its own remember-me too, the price of "everywhere else" meaning it.
     */
    public function destroyOthers(SessionsDestroyOthersRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->rotateRememberToken($user);
        $this->sessionRegistry->destroyOthers($user, $request);

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.settings.sessions.others_revoked'),
            data: null,
        )->toResponse($request);
    }

    /**
     * Sign out one of the user's other sessions, addressed by digest. The current one is refused: signing yourself
     * out belongs to the logout endpoint.
     *
     * A remembered session would sign itself straight back in with its cookie, so revoking one rotates the remember
     * token first, for a recaller arriving in between to meet a dead token.
     * The token is one per account, so every remembered browser loses remember-me; revoking a plain session leaves it alone.
     */
    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $session = $this->sessionRegistry->forUser($request->user())
            ->first(static fn(object $row): bool => hash_equals(hash('sha256', (string) $row->session_id), $sessionId));

        if ($session === null) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.not_found'),
                status: Response::HTTP_NOT_FOUND,
                detail: __('api.settings.sessions.not_found'),
            )->toResponse($request);
        }

        if ((int) $session->id === $this->sessionRegistry->currentRowId($request)) {
            return new JsonErrorResponse(
                title: __('api.errors.titles.validation_failed'),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                detail: __('api.settings.sessions.current_session'),
            )->toResponse($request);
        }

        if ((bool) $session->remembered) {
            $this->rotateRememberToken($request->user());
        }

        $this->sessionRegistry->destroy($request->user(), (int) $session->id);

        return new JsonSuccessResponse(
            status: Response::HTTP_NO_CONTENT,
        )->toResponse($request);
    }

    /**
     * Invalidate every remember-me cookie the user holds, without counting it as a profile update.
     */
    private function rotateRememberToken(User $user): void
    {
        User::withoutTimestamps(function () use ($user): void {
            $user->setRememberToken(Str::random(60));
            $user->saveQuietly();
        });
    }
}
