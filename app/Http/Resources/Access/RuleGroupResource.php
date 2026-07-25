<?php

namespace App\Http\Resources\Access;

use App\Models\Access\RequiredPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One required-permission rule group - the rows sharing a type for a
 * protectable class or record - serialized as {type, mode, permissions[]}.
 * Wraps a collection of RequiredPermission rows with their permission
 * relation loaded.
 */
final class RuleGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, RequiredPermission> $rules */
        $rules = $this->resource;

        return [
            'type' => $rules->first()->type,
            'mode' => $rules->first()->mode,
            'permissions' => NamedResource::collection(
                $rules->map(static fn(RequiredPermission $rule) => $rule->permission)->values()
            ),
        ];
    }
}
