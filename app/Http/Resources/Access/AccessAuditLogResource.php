<?php

namespace App\Http\Resources\Access;

use App\Models\Access\AccessAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One access-audit entry on the admin surfaces (the user detail trail and the role change feed): the action, who
 * performed it, and the scalar before/after snapshots.
 * Tombstoned actors still resolve by name (flagged `deleted`); a null actor means the row predates tombstoning and its
 * admin is gone - the snapshots survive either way.
 *
 * An actor the viewer's record scope does not reach reports `restricted` and nothing else. The callers scope the
 * eager load (UserAccountController::auditLogs(), RoleController::auditLogs()), so this is read off the relation
 * being empty while `actor_id` still points somewhere - redaction by construction rather than by a flag a future
 * caller could forget to set.
 * Nothing partial is emitted: an email is a login handle, a name alone confirms a guess, and the IP is withheld
 * with them - it correlates a redacted actor's entries with each other and with any address the viewer can see
 * elsewhere (their own authentication log, a shared office range), which is the identification the marker refuses.
 */
final class AccessAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AccessAuditLog $entry */
        $entry = $this->resource;

        // An actor the entry names but this viewer may not see: the scoped eager load left the relation empty
        // while actor_id still points somewhere.
        $restricted = $entry->actor === null && $entry->actor_id !== null;

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
                $restricted => ['restricted' => true],
                // No actor recorded at all: the account was hard-deleted before tombstoning shipped.
                default => null,
            },
            'before' => $entry->before,
            'after' => $entry->after,
            // Null rather than absent: the column is nullable anyway, so a withheld address is shaped exactly
            // like an unrecorded one and consumers need no second branch.
            'ip_address' => $restricted ? null : $entry->ip_address,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }
}
