<?php

namespace App\Models\Access;

use App\Services\Access\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One required-permission rule: performing `type` on the protectable (a record, or the whole class when protectable_id is null)
 * requires the referenced permission, combined within its group by `mode`.
 *
 * Rules are written exclusively through AccessControlService, which owns group consistency and auditing.
 */
class RequiredPermission extends Model
{
    public const string MODE_ALL = 'all';

    public const string MODE_ANY = 'any';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'permission_id',
        'protectable_type',
        'protectable_id',
        'type',
        'mode',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission_id' => 'integer',
            'protectable_id' => 'integer',
        ];
    }

    /**
     * Keep the per-request rule memo honest within the mutating request.
     */
    protected static function booted(): void
    {
        $flush = static fn() => app(AccessScope::class)->flushRules();

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * The permission this rule requires.
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(config('permission.models.permission'));
    }

    /**
     * The protected record (null relation for class-level rules).
     */
    public function protectable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to class-level rules (no specific record).
     */
    public function scopeClassLevel(Builder $query): Builder
    {
        return $query->whereNull('protectable_id');
    }

    /**
     * Scope to rules for one specific record.
     */
    public function scopeForRecord(Builder $query, string $alias, int $recordId): Builder
    {
        return $query->where('protectable_type', $alias)->where('protectable_id', $recordId);
    }
}
