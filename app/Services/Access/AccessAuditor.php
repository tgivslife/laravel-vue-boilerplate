<?php

namespace App\Services\Access;

use App\Models\Access\AccessAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes access-audit entries.
 *
 * Two kinds of events share the trail: administrative mutations (recorded by AccessControlService inside its guarded transactions)
 * and self-service security events - enrolling or dropping a second factor, connecting or disconnecting
 * an identity - where the actor is the account owner.
 * The line for what belongs here: events that add or remove a way into an account, no matter who performed them.
 */
readonly class AccessAuditor
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(User $actor, string $action, ?Model $subject, ?array $before, ?array $after): void
    {
        AccessAuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }
}
