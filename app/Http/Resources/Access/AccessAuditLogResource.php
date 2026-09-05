<?php

namespace App\Http\Resources\Access;

use App\Models\Access\AccessAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One access-audit entry on the admin user detail page: the action, who performed it, and the scalar before/after snapshots.
 * Tombstoned actors still resolve by name (flagged `deleted`); a null actor means the row predates tombstoning and its
 * admin is gone - the snapshots survive either way.
 *
 * An actor the viewer's record scope does not reach reports `restricted` and nothing else. The caller scopes the
 * eager load (UserAccountController::auditLogs()), so this is read off the relation being empty while `actor_id`
 * still points somewhere - redaction by construction rather than by a flag a future caller could forget to set.
 * Nothing partial is emitted: an email is a login handle, and a name alone confirms a guess.
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
            'actor' => match (true) {
                $entry->actor !== null => [
                    'id' => $entry->actor->getKey(),
                    'first_name' => $entry->actor->first_name,
                    'last_name' => $entry->actor->last_name,
                    'email' => $entry->actor->email,
                    'deleted' => $entry->actor->trashed(),
                    'restricted' => false,
                ],
                // An actor the entry names but this viewer may not see.
                $entry->actor_id !== null => ['restricted' => true],
                // No actor recorded at all: the account was hard-deleted before tombstoning shipped.
                default => null,
            },
            'before' => $entry->before,
            'after' => $entry->after,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }
}
