<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One login episode (successful or failed) for an authenticatable model.
 *
 * Rows are written by the listeners in app/Listeners/Auth on the framework's
 * Login/Failed/Logout events and pruned by auth:purge-authentication-logs.
 * The row lifecycle lives in login_at/logout_at/last_activity_at, so the
 * default timestamps are disabled.
 */
class AuthenticationLog extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ip_address',
        'user_agent',
        'device_id',
        'device_name',
        'login_at',
        'login_successful',
        'login_method',
        'logout_at',
        'last_activity_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'login_successful' => 'boolean',
            'logout_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * The model whose authentication this row records.
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to successful logins.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('login_successful', true);
    }

    /**
     * Scope to failed login attempts.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('login_successful', false);
    }

    /**
     * Scope to rows recorded from the given device fingerprint.
     */
    public function scopeFromDevice(Builder $query, string $deviceId): Builder
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope to sessions that have not been closed by a logout.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->successful()->whereNull('logout_at');
    }
}
