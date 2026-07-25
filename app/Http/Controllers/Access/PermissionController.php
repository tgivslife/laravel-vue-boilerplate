<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\PermissionIndexRequest;
use App\Http\Resources\Access\NamedResource;
use App\Http\Responses\JsonSuccessResponse;
use App\Models\User;
use App\Support\Access\EscapedLikeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read-only capability vocabulary. Permissions are code-seeded from
 * config/access.php - there is deliberately no create/delete endpoint.
 */
class PermissionController extends Controller
{
    /**
     * Permissions in the configured guard, newest first. `filter[search]`
     * matches the name (bound LIKE); `per_page` turns on pagination for the
     * permissions browser - without it the full vocabulary is returned,
     * which is how the dictionary consumers (grant editors, transfer lists)
     * read it.
     */
    public function index(PermissionIndexRequest $request): JsonResponse
    {
        $query = $this->filteredPermissions();

        $perPage = $request->validated('per_page');
        $page = $perPage === null ? null : $query->paginate((int) $perPage);
        $permissions = collect($page?->items() ?? $query->get()->all());

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Permissions retrieved successfully',
            data: [
                'permissions' => NamedResource::collection($permissions),
                'has_more' => $page?->hasMorePages() ?? false,
                'total' => $page?->total() ?? $permissions->count(),
            ],
        )->toResponse($request);
    }

    /**
     * Coverage summary of the vocabulary in the configured guard, for the permissions page header cards:
     * total size, permissions no role grants, out-of-band direct user grants, and the single most-granted
     * permission (null when the vocabulary is empty).
     */
    public function stats(Request $request): JsonResponse
    {
        $counts = $this->roleCounts();

        $permissions = config('permission.models.permission')::query()
            ->where('guard_name', config('access.guard'))
            ->get(['id', 'name'])
            ->map(static fn(PermissionContract $permission): array => [
                'id' => $permission->getKey(),
                'name' => $permission->name,
                'roles_count' => $counts[$permission->getKey()] ?? 0,
            ]);

        $mostGranted = $permissions
            ->sortBy([['roles_count', 'desc'], ['name', 'asc']])
            ->first();

        $directGrants = DB::table(config('permission.table_names.model_has_permissions'))
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn('permission_id', $permissions->pluck('id'))
            ->count();

        return new JsonSuccessResponse(
            status: Response::HTTP_OK,
            message: 'Permission statistics retrieved successfully',
            data: [
                'stats' => [
                    'total' => $permissions->count(),
                    'unassigned' => $permissions->where('roles_count', 0)->count(),
                    'direct_grants' => $directGrants,
                    'most_granted' => $mostGranted === null ? null : [
                        'name' => $mostGranted['name'],
                        'roles_count' => $mostGranted['roles_count'],
                    ],
                ],
            ],
        )->toResponse($request);
    }

    /**
     * The filter surface of the permissions list: bound LIKE search over
     * the name, newest first - the same shape as the roles browser.
     */
    private function filteredPermissions(): QueryBuilder
    {
        return QueryBuilder::for(
            config('permission.models.permission')::query()
                ->where('guard_name', config('access.guard'))
                ->select(['id', 'name'])
        )
            ->allowedFilters(AllowedFilter::custom('search', new EscapedLikeFilter(['name'])))
            ->allowedSorts('id')
            ->defaultSort('-id');
    }

    /**
     * Granting-role counts read from the pivot directly, keyed by
     * permission id.
     *
     * @return array<int, int>
     */
    private function roleCounts(): array
    {
        return DB::table(config('permission.table_names.role_has_permissions'))
            ->selectRaw('permission_id, count(*) as total')
            ->groupBy('permission_id')
            ->pluck('total', 'permission_id')
            ->map(static fn($total): int => (int) $total)
            ->all();
    }
}
