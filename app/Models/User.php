<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasPreferences;
use App\Notifications\ResetPasswordNotification;
use App\Services\Access\DeletedEmailHasher;
use App\Support\Device;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasPreferences, HasRoles, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'deleted_email_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'require_password_reset' => 'boolean',
            'is_active' => 'boolean',
            'banned_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_verified_step' => 'integer',
            'two_factor_required' => 'boolean',
            'preferences' => 'array',
        ];
    }

    /**
     * Emails are lowercase at rest: lookups (login, magic link, password reset, OIDC auto-link, unique validation)
     * compare with `=` under case-sensitive collations (the default pgsql), so the write side normalizes here - one choke
     * point covering every path that creates or updates an account, whichever entry point it came through.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn(string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * Whether the administrative enrollment mandate is unmet: the account is flagged `two_factor_required` but has no confirmed enrollment.
     * Gates the app (EnsureTwoFactorEnrolled) and drives the SPA's redirect to the enrollment screen;
     * A confirmed enrollment or the feature kill switch satisfies it.
     */
    public function mustEnrollTwoFactor(): bool
    {
        return (bool) config('security.two_factor.enabled', true)
            && $this->two_factor_required
            && !$this->hasTwoFactorEnabled();
    }

    /**
     * Whether TOTP two-factor authentication is active on this account.
     *
     * Only a confirmed enrollment counts: a secret without `two_factor_confirmed_at` is a setup the user never finished
     * and must not lock them out at login.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Filter to accounts that held the given email before deletion tombstoned it (membership lookup).
     * Combine with onlyTrashed()/withTrashed(); a live account never carries a tombstone hash.
     */
    public function scopeWhereDeletedEmail(Builder $query, string $email): Builder
    {
        return $query->where('deleted_email_hash', app(DeletedEmailHasher::class)->hash($email));
    }

    /**
     * Whether this account may authenticate at all.
     *
     * The single source of truth for account-state gating, shared by every login strategy (password and magic link)
     * and by the middleware that cuts off already-authenticated sessions.
     * Callers must only surface this AFTER credentials or a token have been verified, so the outcome never becomes an account-probing oracle.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && $this->banned_at === null;
    }

    /**
     * The user's authentication log (login episodes, successful and failed).
     */
    public function authentications(): MorphMany
    {
        return $this->morphMany(AuthenticationLog::class, 'authenticatable');
    }

    /**
     * The user's linked external identities (OIDC providers).
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * The user's magic-link tokens, whatever their purpose (self-serve login links and admin invitations).
     */
    public function magicLinkTokens(): HasMany
    {
        return $this->hasMany(MagicLinkToken::class);
    }

    /**
     * The user's admin-invitation tokens (first-sign-in links).
     * A live one means the account is still awaiting its invited first sign-in.
     */
    public function invitationTokens(): HasMany
    {
        return $this->magicLinkTokens()->where('purpose', MagicLinkToken::PURPOSE_INVITATION);
    }

    /**
     * Whether the account is still inside its invited-onboarding window: passwordless, unverified, never signed in,
     * and able to authenticate. A password means temporary-password onboarding took over; a verified email or a
     * recorded login means the invitation already did its job.
     *
     * The single predicate behind the invitation-resend guard and the admin browser's invited state, so an account
     * the guard refuses can never keep wearing the badge.
     */
    public function isInvitable(): bool
    {
        return $this->password === null
            && $this->email_verified_at === null
            && $this->last_login_at === null
            && $this->canAuthenticate();
    }

    /**
     * Whether a live invitation link is still awaiting this account's first sign-in.
     *
     * Reads the `invitation_pending` exists-flag when the query eager-loaded it (the admin browser's withExists);
     * Single-account reads fall back to one query, skipped entirely once the account is no longer invitable,
     * a stale link changes nothing then.
     */
    public function hasPendingInvitation(): bool
    {
        if (!$this->isInvitable()) {
            return false;
        }

        if (array_key_exists('invitation_pending', $this->getAttributes())) {
            return (bool) $this->invitation_pending;
        }

        return $this->invitationTokens()->live()->exists();
    }

    /**
     * Send the password-reset mail (framework customization hook).
     *
     * Overrides the CanResetPassword default to send the app's branded, queued notification instead,
     * carrying the SPA reset URL and a scalar snapshot of the requesting device (see ResetPasswordNotification).
     * Only called by the password broker, so the current request is always the forgot-password HTTP request.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = url('/auth/password/reset').'?'.http_build_query([
                'token' => $token,
                'email' => $this->email,
            ]);

        $this->notify(
            new ResetPasswordNotification(
                url: $url,
                expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
                deviceName: Device::name(request()),
                ipAddress: request()->ip(),
                requestedAt: now(),
            )->locale(app()->getLocale())
        );
    }
}
