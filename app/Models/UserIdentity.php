<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link between a local account and an external identity provider.
 *
 * `subject` is the provider's opaque OIDC `sub` claim - the only identity
 * datum persisted (data minimization; see the migration). Rows are created
 * by explicit linking or, under the `email` link policy, on first provider
 * login with a verified matching email (IdentityProviderService).
 */
class UserIdentity extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'subject',
        'last_used_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The account this identity belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
