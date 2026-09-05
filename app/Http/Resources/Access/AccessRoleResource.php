<?php

namespace App\Http\Resources\Access;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Contracts\Role as RoleContract;

/**
 * A role in the role x permission matrix. The super-admin role is flagged
 * protected; the holder count is computed by the controller, which reads
 * the pivot directly (see RoleController::userCounts()).
 */
final class AccessRoleResource extends JsonResource
{
    /**
     * Never build this resource through ::collection(): mapInto() passes each collection
     * key as the second constructor argument, which would silently turn holder counts
     * into row indexes. The controllers map() explicitly, passing the count on purpose.
     */
    public function __construct($resource, private readonly int $usersCount = 0)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var RoleContract $role */
        $role = $this->resource;

        return [
            'id' => $role->getKey(),
            'name' => $role->name,
            'protected' => $role->name === config('access.super_admin_role'),
            'users_count' => $this->usersCount,
            'created_at' => $role->created_at?->toISOString(),
            'permissions' => NamedResource::collection($role->permissions),
        ];
    }
}
