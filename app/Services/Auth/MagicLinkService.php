<?php

namespace App\Services\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Support\Auth\LoginMethod;
use App\Support\Auth\LoginResult;
use App\Support\Device;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Issues and consumes single-use email magic-link tokens.
 *
 * Issue side: enumeration-resistant by construction - {@see send()} returns void no matter what,
 * and the notification is queued, so a caller can never observe whether the email belonged to a user.
 *
 * Consume side: single-use is enforced with one conditional UPDATE (claim), so two concurrent consumptions of the same token can never both win.
 * The emailed link only renders an inert SPA page;
 * Consumption happens through an explicit POST, which keeps mail scanners and prefetchers from burning the token.
 *
 * With `security.magic_link.provision` on, a link requested for an unknown email becomes a signup link.
 * The token carries the email instead of a user id, and the account is created only at consumption,
 * clicking the link proved mailbox ownership, requesting one proves nothing.
 *
 * Admin invitations ({@see invite()}) are a second token purpose on the same machinery: minted for a pre-created account,
 * day-scale TTL, and gated by `security.invitations.enabled` rather than the self-serve door switch, so a
 * password-only deployment can keep the login door closed and still invite.
 */
readonly class MagicLinkService
{
    public function __construct(
        protected MagicLinkTokenHasher $hasher,
        protected TwoFactorChallengeService $challenges,
        protected SelfProvisioningService $provisioner,
    ) {
    }

    /**
     * Issue a magic link for the given email, if it belongs to a usable user - or, with provisioning on,
     * to no user at all (the consumed link will create the account).
     *
     * Deliberately returns void in every case (unknown email, deactivated or banned account, feature disabled),
     * the HTTP response must be identical for all of them.
     * Earlier links stay valid until their own TTL so a delayed email does not strand the user; the TTL bounds the exposure.
     */
    public function send(string $email, ?string $redirect): void
    {
        if (!(bool) config('security.magic_link.enabled', true)) {
            return;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null && !(bool) config('security.magic_link.provision', false)) {
            return;
        }

        if ($user !== null && !$user->canAuthenticate()) {
            return;
        }

        $plaintext = $this->generateToken();
        $ttlMinutes = (int) config('security.magic_link.ttl_minutes', 15);

        MagicLinkToken::query()->create([
            'user_id' => $user?->id,
            // Normalized so the consume-time lookup and the created account agree regardless of how the address was typed or the database collates.
            'email' => $user === null ? mb_strtolower(trim($email)) : null,
            'purpose' => MagicLinkToken::PURPOSE_LOGIN,
            'token_hash' => $this->hasher->hash($plaintext),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        /*
         * The requesting device is snapshot here as scalars: the notification is queued, and the mail shows
         * the recipient which device asked for the link (anyone can request one for any email).
         * A provisioning link goes to a bare address (no account to notify yet) with the welcome copy.
         */
        $notification = new MagicLinkNotification(
            url: $this->verificationUrl($plaintext, $redirect, provisioning: $user === null),
            expiresInMinutes: $ttlMinutes,
            deviceName: Device::name(request()),
            ipAddress: request()->ip(),
            requestedAt: now(),
            provisioning: $user === null,
        )->locale(app()->getLocale());

        if ($user === null) {
            Notification::route('mail', $email)->notify($notification);
        } else {
            $user->notify($notification);
        }
    }

    /**
     * Issue a first-sign-in invitation link for an admin-created account.
     *
     * Prior unconsumed invitations are revoked rather than left to their TTL: unlike the self-serve door,
     * both sides of the exchange are known, so a resend should leave exactly one live link.
     * The caller owns the feature gate and the pending-state guard (delivery validation on creation,
     * AccessControlService::resendInvitation() on resend) - this method only mints and mails.
     *
     * No requesting-device snapshot: the mail is admin-initiated, so "which device asked for this"
     * would name the admin's browser, not anything the recipient can judge.
     */
    public function invite(User $user): void
    {
        MagicLinkToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', MagicLinkToken::PURPOSE_INVITATION)
            ->whereNull('consumed_at')
            ->delete();

        $plaintext = $this->generateToken();
        $ttlDays = (int) config('security.invitations.ttl_days', 7);

        MagicLinkToken::query()->create([
            'user_id' => $user->id,
            'purpose' => MagicLinkToken::PURPOSE_INVITATION,
            'token_hash' => $this->hasher->hash($plaintext),
            'expires_at' => now()->addDays($ttlDays),
        ]);

        $user->notify(
            new InvitationNotification(
                url: $this->verificationUrl($plaintext, null, invitation: true),
                expiresInDays: $ttlDays,
                requiresPassword: (bool) $user->require_password_reset,
            )->locale(app()->getLocale())
        );
    }

    /**
     * Consume a magic-link token and establish a session for its user.
     *
     * The session-state guards run before the claim so those outcomes never burn a still-valid token.
     * All token failures (unknown, expired, already used) collapse into one indistinguishable `invalidMagicLink` result.
     * Account-state checks run after the claim, so a rejected token is spent either way.
     * On success the session is regenerated to prevent fixation, and the email is marked verified,
     * the link just proved mailbox ownership.
     *
     * Enrolled accounts are parked for the two-factor challenge instead of logged in;
     * The link proves the mailbox, not the second factor, and a compromised inbox alone must never become an account takeover.
     * The token is spent even when the challenge is abandoned.
     *
     * A provisioning token (null user id) creates its account here, where the mailbox is proven - unless an
     * account with that email appeared since the send, in which case the link simply signs into it: the
     * mailbox guarantee is the same either way.
     *
     * Each purpose answers to its own switch (`magic_link.enabled` for login links, `invitations.enabled`
     * for invitations), checked only after the claim - a token's purpose is unknown until its row is read.
     * Outstanding links of a disabled purpose therefore die spent, with the same indistinguishable outcome as expired ones.
     */
    public function consume(string $token): LoginResult
    {
        if (!request()->hasSession()) {
            return LoginResult::sessionUnavailable();
        }

        if (Auth::check()) {
            return LoginResult::alreadyAuthenticated();
        }

        $now = now();
        $hash = $this->hasher->hash($token);

        $claimed = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now]);

        if ($claimed !== 1) {
            return LoginResult::invalidMagicLink();
        }

        $claimedToken = MagicLinkToken::query()
            ->where('token_hash', $hash)
            ->first();

        if ($claimedToken === null) {
            return LoginResult::invalidMagicLink();
        }

        $isInvitation = $claimedToken->purpose === MagicLinkToken::PURPOSE_INVITATION;

        $enabled = $isInvitation
            ? (bool) config('security.invitations.enabled', true)
            : (bool) config('security.magic_link.enabled', true);

        if (!$enabled) {
            return LoginResult::invalidMagicLink();
        }

        $provisioned = false;

        if ($claimedToken->user_id !== null) {
            $user = $claimedToken->user;
        } elseif (!(bool) config('security.magic_link.provision', false)) {
            return LoginResult::invalidMagicLink();
        } else {
            [$user, $provisioned] = $this->resolveOrProvision($claimedToken->email);
        }

        if ($user === null) {
            return LoginResult::invalidMagicLink();
        }

        if (!$user->canAuthenticate()) {
            return LoginResult::accountDeactivated();
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => $now])->save();
        }

        ($isInvitation ? LoginMethod::Invitation : LoginMethod::MagicLink)->declare();

        if ((bool) config('security.two_factor.enabled', true) && $user->hasTwoFactorEnabled()) {
            $this->challenges->stash($user, false);

            return LoginResult::twoFactorRequired();
        }

        Auth::guard('web')->login($user);

        request()->session()->regenerate();

        return LoginResult::success($user, $provisioned ? ['provisioned' => true] : null);
    }

    /**
     * The account a provisioning token signs into: the existing holder of the email when an account
     * appeared since the send, otherwise a freshly provisioned one.
     *
     * The lookup is the fast path; the unique-violation catch settles the race between two provisioning
     * links for the same email by re-resolving the account the winner created - the same idiom the
     * framework's createOrFirst() uses, with the unique index as the arbiter.
     *
     * @return array{0: ?User, 1: bool} The resolved account (null only when even the re-resolve finds nothing) and whether this call created it.
     */
    protected function resolveOrProvision(string $email): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return [$user, false];
        }

        try {
            $user = $this->provisioner->provision(
                email: $email,
                firstName: null,
                lastName: null,
                channel: 'magic_link',
                twoFactorRequired: (bool) config('security.magic_link.provision_two_factor_required', false),
            );

            return [$user, true];
        } catch (UniqueConstraintViolationException) {
            return [User::query()->where('email', $email)->first(), false];
        }
    }

    /**
     * 32 bytes of CSPRNG (Cryptographically Secure Pseudorandom Number Generator) output,
     * URL-safe base64 encoded (43 characters).
     */
    protected function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Build the SPA verification URL carried by the email.
     *
     * The redirect is only forwarded when it is an internal path (single
     * leading slash), so a crafted request cannot turn the email into an open
     * redirect. The SPA applies the same validation again before navigating.
     *
     * Provisioning links carry a `signup` marker and invitations an `invite` marker so the verify page
     * can adapt its copy. Cosmetic only - consumption ignores them - and no leak: they ride inside the
     * secret link, whose only reader the mail already told.
     */
    protected function verificationUrl(
        string $plaintext,
        ?string $redirect,
        bool $provisioning = false,
        bool $invitation = false
    ): string {
        $query = ['token' => $plaintext];

        if ($provisioning) {
            $query['signup'] = 1;
        }

        if ($invitation) {
            $query['invite'] = 1;
        }

        if (is_string($redirect)
            && str_starts_with($redirect, '/')
            && !str_starts_with($redirect, '//')
            && !str_starts_with($redirect, '/\\')
        ) {
            $query['redirect'] = $redirect;
        }

        return url('/auth/magic/verify').'?'.http_build_query($query);
    }
}
