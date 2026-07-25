<?php

namespace App\Models\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the access audit trail: an administrative mutation (role/permission/rule change, written in the same
 * transaction as the change itself) or a self-service security event (second factor enrolled/dropped, identity
 * connected/disconnected) where the actor is the account owner.
 * Snapshots are scalar arrays so the trail stays readable after the actor or subject is deleted.
 */
class AccessAuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'ip_address',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who performed the change: an administrator, or the account owner for self-service security events.
     * Resolved through the soft-delete scope: names survive tombstoning, and a trail
     * whose actors go anonymous on deletion would defeat its purpose.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
