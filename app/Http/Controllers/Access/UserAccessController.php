<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\SyncPermissionsRequest;
use App\Http\Requests\Access\SyncUserRolesRequest;
use App\Http\Requests\Access\UserIndexRequest;
use App\Http\Requests\Access\UserMembershipRequest;
use App\Http\Requests\Access\UserStoreRequest;
use App\Http\Resources\Access\AccessUserResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Support\Access\EscapedLikeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin users browser: who exists, what they hold, headline counts,
 * CSV export, account creation, and the two sync endpoints that reshape a
 * user's roles and direct permissions. Mutations go through
 * AccessControlService (lockout guards + audit).
 */
class UserAccessController extends Controller
{
    public function __construct(
        private readonly AccessControlService $accessControl
    ) {
    }

    /**
     * List users with their roles and direct permissions, newest first.
     * `filter[search]` matches the configured user columns (bound LIKE, no
     * raw SQL); `filter[role_id]` and `filter[status]` narrow the list
     * further; `per_page` picks one of the allowed page sizes. Unknown
     * filter keys are rejected by QueryBuilder with a 400; the request
     * validates the values.
     */
    public function index(UserIndexRequest $request): JsonResponse
    {
        $users = $this->filteredUsers()
            ->with(['roles:id,name', 'permissions:id,name'])
            ->paginate((int) ($request->validated('per_page') ?? config('access.user_browser.per_page', 25)));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Users retrieved successfully',
            data: [
                'users' => AccessUserResource::collection(collect($users->items())),
                'has_more' => $users->hasMorePages(),
                'total' => $users->total(),
            ],
        )->toResponse($request);
    }

    /**
     * Headline counts for the users browser: population, health and
     * this week's intake. The delta compares the new accounts of the
     * last seven days against the seven days before; it is null when
     * the earlier window is empty (no baseline to compare against).
     */
    public function stats(Request $request): JsonResponse
    {
        $weekAgo = now()->subWeek();
        $twoWeeksAgo = now()->subWeeks(2);

        $newThisWeek = User::where('created_at', '>=', $weekAgo)->count();
        $newPreviousWeek = User::whereBetween('created_at', [$twoWeeksAgo, $weekAgo])->count();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'User statistics retrieved successfully',
            data: [
                'stats' => [
                    'total' => User::count(),
                    'active' => User::where('is_active', true)->whereNull('banned_at')->count(),
                    'unverified' => User::whereNull('email_verified_at')->count(),
                    'deleted' => User::onlyTrashed()->count(),
                    'new_this_week' => $newThisWeek,
                    'new_this_week_delta' => $newPreviousWeek > 0
                        ? (int) round((($newThisWeek - $newPreviousWeek) / $newPreviousWeek) * 100)
                        : null,
                ]
            ],
        )->toResponse($request);
    }

    /**
     * Stream the filtered user list as a CSV download. The same filters
     * as the index apply; pagination does not.
     */
    public function export(UserIndexRequest $request): StreamedResponse
    {
        $users = $this->filteredUsers()->with('roles:id,name');

        return new StreamedResponse(static function () use ($users): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'id', 'first_name', 'last_name', 'email', 'email_verified',
                'status', 'invited', 'roles', 'last_login_at', 'created_at',
            ]);

            foreach ($users->lazy() as $user) {
                fputcsv($out, [
                    $user->getKey(),
                    $user->first_name,
                    $user->last_name,
                    $user->email,
                    $user->hasVerifiedEmail() ? 'yes' : 'no',
                    $user->deleted_at !== null
                        ? 'deleted'
                        : ($user->banned_at !== null ? 'banned' : ($user->is_active ? 'active' : 'inactive')),
                    $user->hasPendingInvitation() ? 'yes' : 'no',
                    $user->roles->pluck('name')->sort()->implode('|'),
                    $user->last_login_at?->toISOString(),
                    $user->created_at?->toISOString(),
                ]);
            }

            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    /**
     * Create an account on the user's behalf, with the requested onboarding delivery: a server-generated
     * temporary password (returned exactly once, forced-reset flagged) for the admin to communicate out
     * of band, or a mailed single-use invitation link - in which case no credential ever leaves the server.
     */
    public function store(UserStoreRequest $request): JsonResponse
    {
        $delivery = $request->delivery();

        ['user' => $user, 'temporary_password' => $temporaryPassword] = $this->accessControl->createUser(
            $request->user(),
            $request->safe()->except(['role_ids', 'delivery']),
            $request->validated('role_ids') ?? [],
            $delivery,
        );

        return new JsonSuccessResponse(
            status: Response::HTTP_CREATED,
            message: __($delivery === 'invitation' ? 'api.access.user_invited' : 'api.access.user_created'),
            data: [
                'user' => new AccessUserResource($user->refresh(), detailed: true),
            ] + ($temporaryPassword === null ? [] : ['temporary_password' => $temporaryPassword]),
        )->toResponse($request);
    }

    /**
     * The filter surface shared by the index and the export: bound LIKE search over the configured columns,
     * plus role, status, two-factor posture and onboarding narrowing.
     * `deleted` opts into the tombstoned rows the soft-delete scope hides; every other status only ever sees live accounts.
     * The `two_factor` values mirror the list column's three states; `onboarding` collects the not-fully-landed
     * flavors, with `invited` matching the invitation badge's derivation exactly (invitable + a live link).
     */
    private function filteredUsers(): QueryBuilder
    {
        return QueryBuilder::for(User::class)
            ->withExists(['invitationTokens as invitation_pending' => static fn($query) => $query->live()])
            ->allowedFilters(
                AllowedFilter::custom('search', new EscapedLikeFilter(config('access.user_browser.search_columns'))),
                AllowedFilter::exact('role_id', 'roles.id'),
                AllowedFilter::callback('status', static fn($query, string $status) => match ($status) {
                    'active' => $query->where('is_active', true)->whereNull('banned_at'),
                    'inactive' => $query->where('is_active', false)->whereNull('banned_at'),
                    'banned' => $query->whereNotNull('banned_at'),
                    'deleted' => $query->onlyTrashed(),
                }),
                AllowedFilter::callback('two_factor', static fn($query, string $state) => match ($state) {
                    'enabled' => $query->whereNotNull('two_factor_confirmed_at'),
                    'required' => $query->whereNull('two_factor_confirmed_at')->where('two_factor_required', true),
                    'disabled' => $query->whereNull('two_factor_confirmed_at')->where('two_factor_required', false),
                }),
                AllowedFilter::callback('onboarding', static fn($query, string $state) => match ($state) {
                    'invited' => $query
                        ->whereNull('password')
                        ->whereNull('email_verified_at')
                        ->whereNull('last_login_at')
                        ->where('is_active', true)
                        ->whereNull('banned_at')
                        ->whereHas('invitationTokens', static fn($tokens) => $tokens->live()),
                    'reset_pending' => $query->where('require_password_reset', true),
                    'unverified' => $query->whereNull('email_verified_at'),
                }),
            )
            ->allowedSorts('id')
            ->defaultSort('-id');
    }

    /**
     * Answer "is this email an account - or was it ever?": live accounts by the address itself,
     * retired ones by the tombstone hash (whereDeletedEmail), newest retirement first when the
     * address had several lives. Sits behind users.view, so no enumeration surface is added.
     */
    public function membership(UserMembershipRequest $request): JsonResponse
    {
        $email = trim((string) $request->validated('email'));

        $user = User::query()->where('email', $email)->first();

        $deleted = $user === null
            ? User::onlyTrashed()->whereDeletedEmail($email)->orderByDesc('deleted_at')->first()
            : null;

        $match = $user ?? $deleted;

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Membership retrieved successfully',
            data: [
                'status' => match (true) {
                    $user !== null => 'active',
                    $deleted !== null => 'deleted',
                    default => 'none',
                },
                'user' => $match === null ? null : new AccessUserResource($match),
            ],
        )->toResponse($request);
    }

    /**
     * One user's full access picture, including the effective permission
     * set (direct + via roles) the editor renders as disabled checks.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'User retrieved successfully',
            data: ['user' => new AccessUserResource($user, detailed: true)],
        )->toResponse($request);
    }

    /**
     * Replace the user's roles.
     */
    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $this->accessControl->syncUserRoles($request->user(), $user, $request->validated('role_ids'));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.roles_updated'),
            data: ['user' => new AccessUserResource($user->refresh(), detailed: true)],
        )->toResponse($request);
    }

    /**
     * Replace the user's direct permissions.
     */
    public function syncPermissions(SyncPermissionsRequest $request, User $user): JsonResponse
    {
        $this->accessControl->syncUserPermissions($request->user(), $user, $request->validated('permission_ids'));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.permissions_updated'),
            data: ['user' => new AccessUserResource($user->refresh(), detailed: true)],
        )->toResponse($request);
    }
}
