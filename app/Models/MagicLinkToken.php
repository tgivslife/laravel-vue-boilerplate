<?php

namespace App\Models;

use Database\Factories\MagicLinkTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, time-limited magic-link token.
 *
 * Only the keyed HMAC of the secret is stored (`token_hash`); the plaintext exists solely inside the emailed link.
 * Claiming is done with a conditional UPDATE in MagicLinkService, never by mutating this model directly, so single-use cannot be raced.
 *
 * A row with a null `user_id` is a self-provisioning token: it carries the target `email` instead, and the account
 * does not exist until the token is consumed.
 *
 * `purpose` separates the self-serve login door (`login`) from admin invitations (`invitation`), which share
 * the token machinery but answer to their own feature switch (security.invitations).
 */
class MagicLinkToken extends Model
{
    /** @use HasFactory<MagicLinkTokenFactory> */
    use HasFactory;

    public const string PURPOSE_LOGIN = 'login';

    public const string PURPOSE_INVITATION = 'invitation';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'email',
        'purpose',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to tokens still claimable: unconsumed and unexpired.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
