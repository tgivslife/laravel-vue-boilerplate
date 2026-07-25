<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Access\AccessAuditor;
use App\Support\Auth\IdentityLinkStatus;
use App\Support\Auth\IdentityLoginStatus;
use App\Support\Auth\LoginMethod;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as OidcUser;

/**
 * Maps a validated OIDC identity onto a local session.
 *
 * Matching is by the identity link (provider + subject).
 * A first login may create that link under the `email` policy (verified matching email, existing accounts only)
 * or create the account itself under the `provision` policy (JIT, for providers whose directory is itself
 * administrative - see config/security.php for the guardrails).
 * On success the login runs through the same motions as every other door: account-state gate, method declaration
 * for the authentication log, session regeneration.
 */
readonly class IdentityProviderService
{
    public function __construct(
        protected IdentityProviderRegistry $registry,
        protected TwoFactorChallengeService $challenges,
        protected AccessAuditor $auditor,
        protected SelfProvisioningService $provisioner,
    ) {
    }

    /**
     * Establish a session for the identity's user.
     */
    public function authenticate(string $provider, OidcUser $oidcUser): IdentityLoginStatus
    {
        $subject = (string) $oidcUser->getId();

        $identity = UserIdentity::query()
            ->where('provider', $provider)
            ->where('subject', $subject)
            ->first();

        $identity ??= match ($this->registry->linkPolicy($provider)) {
            'email' => $this->autoLink($provider, $oidcUser),
            'provision' => $this->provision($provider, $oidcUser),
            default => null,
        };

        if ($identity === null) {
            return IdentityLoginStatus::NotLinked;
        }

        $user = $identity->user;

        if ($user === null || !$user->canAuthenticate()) {
            return IdentityLoginStatus::AccountUnavailable;
        }

        LoginMethod::from($provider)->declare();

        /*
         * Per-provider knob:
         * 'skip' (default) trusts the IdP to own MFA for its identities;
         * 'require' parks enrolled accounts for the app challenge.
         * The identity itself verified either way, so its last_used_at moves in both branches.
         */
        if ($this->registry->requiresTwoFactor($provider)
            && (bool) config('security.two_factor.enabled', true)
            && $user->hasTwoFactorEnabled()
        ) {
            $this->challenges->stash($user, false);

            $identity->forceFill(['last_used_at' => now()])->save();

            return IdentityLoginStatus::TwoFactorRequired;
        }

        Auth::guard('web')->login($user);

        request()->session()->regenerate();

        $identity->forceFill(['last_used_at' => now()])->save();

        return IdentityLoginStatus::Success;
    }

    /**
     * Link an identity to the signed-in account (the `connect` intent).
     *
     * One identity per provider per account: a second subject for an already-linked provider is refused
     * rather than silently replaced - the user must disconnect first, which keeps every change of
     * identity an explicit, password-confirmed act.
     */
    public function link(User $user, string $provider, OidcUser $oidcUser): IdentityLinkStatus
    {
        $subject = (string) $oidcUser->getId();

        $existing = UserIdentity::query()
            ->where('provider', $provider)
            ->where('subject', $subject)
            ->first();

        if ($existing !== null) {
            return $existing->user_id === $user->getKey()
                ? IdentityLinkStatus::AlreadyLinked
                : IdentityLinkStatus::SubjectTaken;
        }

        if ($user->identities()->where('provider', $provider)->exists()) {
            return IdentityLinkStatus::ProviderAlreadyLinked;
        }

        $user->identities()->create([
            'provider' => $provider,
            'subject' => $subject,
        ]);

        $this->auditIdentityLinked($user, $provider);

        return IdentityLinkStatus::Linked;
    }

    /**
     * Link on first login under the `email` policy.
     *
     * Requires the provider's boolean email_verified claim to be strictly true: an unverified address
     * must never bind an external identity to a local account (account-takeover surface).
     */
    private function autoLink(string $provider, OidcUser $oidcUser): ?UserIdentity
    {
        $email = $this->verifiedEmail($oidcUser);

        if ($email === null) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return null;
        }

        $identity = $user->identities()->create([
            'provider' => $provider,
            'subject' => (string) $oidcUser->getId(),
        ]);

        $this->auditIdentityLinked($user, $provider);

        return $identity;
    }

    /**
     * Create the account on first login under the `provision` policy (JIT).
     *
     * Guardrails: a verified email; the optional claim gate (the IdP must assert membership, not mere existence);
     * and a hard refusal when the email already belongs to a local account - binding an external identity to
     * an account it did not create is the (stricter) `email` policy's job.
     * Creation itself is SelfProvisioningService's: verified email, the configured default roles, audit entry.
     */
    private function provision(string $provider, OidcUser $oidcUser): ?UserIdentity
    {
        $email = $this->verifiedEmail($oidcUser);
        $claims = (array) $oidcUser->getRaw();

        if ($email === null || !$this->passesProvisionGate($provider, $claims)) {
            return null;
        }

        if (User::query()->where('email', $email)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($provider, $oidcUser, $claims, $email): UserIdentity {
            $name = trim((string) ($claims['name'] ?? ''));

            $user = $this->provisioner->provision(
                email: $email,
                firstName: trim((string) ($claims['given_name'] ?? '')) ?: Str::of($name)->before(' ')->trim()->toString(),
                lastName: trim((string) ($claims['family_name'] ?? '')) ?: Str::of($name)->after(' ')->trim()->toString(),
                channel: $provider,
            );

            $identity = $user->identities()->create([
                'provider' => $provider,
                'subject' => (string) $oidcUser->getId(),
            ]);

            $this->auditIdentityLinked($user, $provider);

            return $identity;
        });
    }

    /**
     * Self-service security event, whichever path created the link: the explicit connect intent,
     * the `email` auto-link or a `provision` first login.
     * A new way into the account always leaves a trail.
     */
    private function auditIdentityLinked(User $user, string $provider): void
    {
        $this->auditor->record($user, 'user.identity_linked', $user, null, ['provider' => $provider]);
    }

    /**
     * The provider's email claim, only when it vouches for it - normalized to lowercase so
     * account lookups and creation agree regardless of the claim's casing.
     */
    private function verifiedEmail(OidcUser $oidcUser): ?string
    {
        $claims = (array) $oidcUser->getRaw();
        $email = trim((string) ($oidcUser->getEmail() ?? ''));

        if ($email === '' || ($claims['email_verified'] ?? false) !== true) {
            return null;
        }

        return mb_strtolower($email);
    }

    /**
     * The optional provision claim gate: when configured, the token must carry the claim (and, if a value is configured,
     * carry that value - or contain it, for array claims like Keycloak realm roles).
     *
     * @param  array<string, mixed>  $claims
     */
    private function passesProvisionGate(string $provider, array $claims): bool
    {
        $gate = $this->registry->provisionGate($provider);
        $claimPath = (string) ($gate['claim'] ?? '');

        if ($claimPath === '') {
            return true;
        }

        $value = Arr::get($claims, $claimPath);
        $required = $gate['value'];

        if ($required === null || $required === '') {
            return filled($value);
        }

        return is_array($value)
            ? in_array($required, $value, true)
            : (string) $value === (string) $required;
    }
}
