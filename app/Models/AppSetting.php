<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as RecordsAudits;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One stored override of an admin-editable app-level setting.
 *
 * The registry at config/settings.php (app) is the closed vocabulary; rows exist only for keys
 * an administrator changed away from their default, and resetting to the default deletes the row.
 * All access goes through the AppSettings service (registry check, validation, cache).
 *
 * Auditable: every created/updated/deleted override lands in the attribute-level audit trail -
 * this model is the trail's production reference implementation.
 */
class AppSetting extends Model implements Auditable
{
    use RecordsAudits;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
