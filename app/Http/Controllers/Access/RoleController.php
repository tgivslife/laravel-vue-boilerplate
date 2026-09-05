<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RoleIndexRequest;
use App\Http\Requests\Access\RoleStoreRequest;
use App\Http\Requests\Access\RoleUpdateRequest;
use App\Http\Requests\Access\SyncPermissionsRequest;
use App\Http\Resources\Access\AccessRoleResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Support\Access\EscapedLikeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role management and the role x permission matrix. The super-admin role is
 * listed (so admins can see who holds it) but flagged protected; the
 * service refuses to modify it.
 */
class RoleController extends Controller
{
    /**
     * Holder counts per role id, computed once per request.
     *
     * @var array<int, int>|null
     */
    private ?array $userCounts = null;

    public function __construct(
        private readonly AccessControlService $accessControl
    ) {
    }

    /**
     * Roles in the configured guard, with permissions and holder count,
     * newest first. `filter[search]` matches the name (bound LIKE);
     * `per_page` turns on pagination for the roles browser - without it the
     * full list is returned, which is how the dictionary consumers (user
     * filters, grant editors) read it.
     */
    public function index(RoleIndexRequest $request): JsonResponse
    {
        $query = $this->filteredRoles();

        $perPage = $request->validated('per_page');
        $page = $perPage === null ? null : $query->paginate((int) $perPage);
        $roles = collect($page?->items() ?? $query->get()->all());

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Roles retrieved successfully',
            data: [
                'roles' => $roles->map(fn(Role $role): AccessRoleResource => $this->resource($role))->all(),
                'has_more' => $page?->hasMorePages() ?? false,
                'total' => $page?->total() ?? $roles->count(),
            ],
        )->toResponse($request);
    }

    /**
     * The filter surface of the roles list: bound LIKE search over the
     * name, newest first - the same shape as the users browser.
     */
    private function filteredRoles(): QueryBuilder
    {
        return QueryBuilder::for(
            Role::query()
                ->where('guard_name', config('access.guard'))
                ->with('permissions:id,name')
        )
            ->allowedFilters(AllowedFilter::custom('search', new EscapedLikeFilter(['name'])))
            ->allowedSorts('id')
            ->defaultSort('-id');
    }

    /**
     * Composition summary of the roles in the configured guard, for the roles page header cards:
     * total count, roles nobody holds, roles granting no permissions, and active (unbanned) super-admin holders.
     * The super-admin role is exempt from the hygiene counts - it grants everything implicitly and has its own card.
     */
    public function stats(Request $request): JsonResponse
    {
        $counts = $this->userCounts();

        $permissionCounts = DB::table(config('permission.table_names.role_has_permissions'))
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->all();

        $roles = Role::query()
            ->where('guard_name', config('access.guard'))
            ->get(['id', 'name']);

        $regular = $roles->where('name', '!=', config('access.super_admin_role'));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Role statistics retrieved successfully',
            data: [
                'stats' => [
                    'total' => $roles->count(),
                    'unused' => $regular->filter(static fn(Role $role
                    ): bool => ($counts[$role->getKey()] ?? 0) === 0)->count(),
                    'empty' => $regular->filter(static fn(Role $role
                    ): bool => !isset($permissionCounts[$role->getKey()]))->count(),
                    'super_admin_holders' => $this->activeSuperAdminHolders($roles->firstWhere('name',
                        config('access.super_admin_role'))),
                ],
            ],
        )->toResponse($request);
    }

    /**
     * Active (unbanned) holders of the super-admin role; zero when the role has not been created.
     * Mirrors the active-holder definition the lockout guards use.
     */
    private function activeSuperAdminHolders(?Role $superAdmin): int
    {
        if ($superAdmin === null) {
            return 0;
        }

        $holderIds = DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $superAdmin->getKey())
            ->where('model_type', (new User)->getMorphClass())
            ->pluck(config('permission.column_names.model_morph_key'));

        return User::query()
            ->whereIn('id', $holderIds)
            ->where('is_active', true)
            ->whereNull('banned_at')
            ->count();
    }

    /**
     * One role with its permissions and holder count, for the role detail page.
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Role retrieved successfully',
            data: ['role' => $this->resource($role)],
        )->toResponse($request);
    }

    public function store(RoleStoreRequest $request): JsonResponse
    {
        $role = $this->accessControl->createRole($request->user(), $request->validated('name'));

        return new JsonSuccessResponse(
            status: Response::HTTP_CREATED,
            message: __('api.access.role_created'),
            data: ['role' => $this->resource($role->refresh())],
        )->toResponse($request);
    }

    public function update(RoleUpdateRequest $request, Role $role): JsonResponse
    {
        $this->accessControl->renameRole($request->user(), $role, $request->validated('name'));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.role_updated'),
            data: ['role' => $this->resource($role->refresh())],
        )->toResponse($request);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->accessControl->deleteRole($request->user(), $role);

        return new JsonSuccessResponse(
            status: Response::HTTP_NO_CONTENT,
        )->toResponse($request);
    }

    /**
     * Replace the role's permissions (one matrix row).
     */
    public function syncPermissions(SyncPermissionsRequest $request, Role $role): JsonResponse
    {
        $this->accessControl->syncRolePermissions($request->user(), $role, $request->validated('permission_ids'));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: __('api.access.permissions_updated'),
            data: ['role' => $this->resource($role->refresh())],
        )->toResponse($request);
    }

    private function resource(RoleContract $role): AccessRoleResource
    {
        return new AccessRoleResource($role, $this->userCounts()[$role->getKey()] ?? 0);
    }

    /**
     * Holder counts read from the pivot directly.
     * Spatie's Role::users() resolves its related model from the request's default guard, which auth:sanctum rewrites
     * to the provider-less runtime `sanctum` guard - withCount('users') would fatal here.
     *
     * @return array<int, int>
     */
    private function userCounts(): array
    {
        return $this->userCounts ??= DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User)->getMorphClass())
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->map(static fn($total): int => (int) $total)
            ->all();
    }
}
