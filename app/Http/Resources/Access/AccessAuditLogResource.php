<?php

namespace App\Http\Resources\Access;

use App\Models\Access\AccessAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One access-audit entry on the admin user detail page: the action, who performed it, and the scalar before/after snapshots.
 * Tombstoned actors still resolve by name (flagged `deleted`); a null actor means the row predates tombstoning and its
 * admin is gone - the snapshots survive either way.
 */
final class AccessAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AccessAuditLog $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->getKey(),
            'action' => $entry->action,
            'actor' => $entry->actor === null ? null : [
                'id' => $entry->actor->getKey(),
                'first_name' => $entry->actor->first_name,
                'last_name' => $entry->actor->last_name,
                'email' => $entry->actor->email,
                'deleted' => $entry->actor->trashed(),
            ],
            'before' => $entry->before,
            'after' => $entry->after,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }
}
