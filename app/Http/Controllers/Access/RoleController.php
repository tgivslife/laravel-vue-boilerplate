<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RoleAuditLogIndexRequest;
use App\Http\Requests\Access\RoleIndexRequest;
use App\Http\Requests\Access\RoleStoreRequest;
use App\Http\Requests\Access\RoleUpdateRequest;
use App\Http\Requests\Access\SyncPermissionsRequest;
use App\Http\Resources\Access\AccessAuditLogResource;
use App\Http\Resources\Access\AccessRoleResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\Access\AccessAuditLog;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Support\Access\EscapedLikeFilter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * The role-surface change feed: every audit entry with a role as subject, newest first - creations,
     * renames, permission syncs and deletions - optionally narrowed to one role with `filter[role_id]`.
     *
     * Roles hard-delete (no tombstones), so this feed is the durable record: entries outlive their subject,
     * and each one carries a `role` block resolved server-side - the live row's current name when it still
     * exists, the deletion entry's snapshot name otherwise, flagged `deleted`.
     *
     * The actor eager load is scoped exactly like the user trail's (UserAccountController::auditLogs()):
     * an actor outside the viewer's record scope never loads, and the resource reports it `restricted`.
     */
    public function auditLogs(RoleAuditLogIndexRequest $request): JsonResponse
    {
        // Narrowed on presence, not truthiness: passing the value straight to when() would read a submitted
        // 0 as "no filter given" and answer an explicitly narrowed request with the whole surface.
        $roleId = $request->validated('filter.role_id');

        $entries = AccessAuditLog::query()
            ->where('subject_type', (new Role)->getMorphClass())
            ->when($roleId !== null, static fn($query) => $query->where('subject_id', (int) $roleId))
            ->with([
                'actor' => static function (BelongsTo $actor) use ($request): void {
                    $actor->visibleTo($request->user())
                        ->select(['id', 'first_name', 'last_name', 'email', 'deleted_at']);
                }
            ])
            ->orderByDesc('id')
            ->simplePaginate((int) config('access.audit_log.page_size', 15));

        $roles = $this->subjectRoles(collect($entries->items()));

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Audit log retrieved successfully',
            data: [
                'entries' => collect($entries->items())->map(
                    static fn(AccessAuditLog $entry): array => new AccessAuditLogResource($entry)->resolve($request)
                        + ['role' => $roles[(int) $entry->subject_id] ?? null],
                )->all(),
                'has_more' => $entries->hasMorePages(),
            ],
        )->toResponse($request);
    }

    /**
     * The subject `role` block for one feed page, resolved in at most two queries: live rows answer with their
     * current name, gone ids fall back to the name their `role.deleted` entry snapshotted.
     *
     * @param  Collection<int, AccessAuditLog>  $entries
     * @return array<int, array{id: int, name: ?string, deleted: bool}>
     */
    private function subjectRoles(Collection $entries): array
    {
        $ids = $entries->pluck('subject_id')
            ->filter()
            ->map(static fn(mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $live = Role::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        $deletedIds = $ids->reject(static fn(int $id): bool => isset($live[$id]))->values();

        // Ordered ascending so, should an id ever repeat, the newest deletion's snapshot wins the key.
        $deletedNames = $deletedIds->isEmpty() ? collect() : AccessAuditLog::query()
            ->where('subject_type', (new Role)->getMorphClass())
            ->whereIn('subject_id', $deletedIds)
            ->where('action', 'role.deleted')
            ->orderBy('id')
            ->get(['subject_id', 'before'])
            ->mapWithKeys(static fn(AccessAuditLog $entry): array => [
                (int) $entry->subject_id => $entry->before['name'] ?? null,
            ]);

        return $ids->mapWithKeys(static fn(int $id): array => [
            $id => [
                'id' => $id,
                'name' => $live[$id] ?? $deletedNames[$id] ?? null,
                'deleted' => !isset($live[$id]),
            ],
        ])->all();
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
