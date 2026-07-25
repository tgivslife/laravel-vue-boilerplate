<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * One entry of the attribute-level audit trail: a created/updated/deleted/restored diff recorded
 * automatically for any model carrying the Auditable trait (see config/audit.php).
 * Complements the intent-level access_audit_logs trail rather than replacing it.
 */
class Audit extends BaseAudit
{
    /**
     * The administrator whose session was borrowed when this write happened mid-impersonation, if any,
     * the target stays the user morph, so the trail names both parties.
     * Resolved through the soft-delete scope: names survive tombstoning, and a trail whose actors go
     * anonymous on deletion would defeat its purpose.
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id')->withTrashed();
    }
}
