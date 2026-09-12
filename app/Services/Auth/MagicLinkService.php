<?php

namespace App\Services\Auth;

use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Support\Auth\LoginMethod;
use App\Support\Auth\LoginResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Timebox;

/**
 * Issues and consumes single-use email magic-link tokens.
 *
 * Issue side: enumeration-resistant by construction. {@see send()} returns void whatever it found, the mail is
 * queued and resolves the requesting device only when it renders, and the whole decision runs inside a timebox of
 * the service's own, so its duration is the floor whichever branch ran - an early return would otherwise answer in
 * under a millisecond against the token work of a usable account. The floor is a measured starting point above
 * that work, not a guarantee.
 *
 * Consume side: single-use is enforced with one conditional UPDATE (claim), so two concurrent consumptions of the
 * same token can never both win. The emailed link only renders an inert SPA page; consumption is an explicit POST,
 * which keeps mail scanners and prefetchers from burning the token.
 *
 * With `security.magic_link.provision` on, a link requested for an unknown email becomes a signup link: the token
 * carries the email instead of a user id, and the account is created only at consumption - clicking the link
 * proved mailbox ownership, requesting one proves nothing.
 *
 * Admin invitations ({@see invite()}) are a second token purpose on the same machinery: minted for a pre-created
 * account, day-scale TTL, and gated by `security.invitations.enabled` rather than the self-serve door switch, so a
 * password-only deployment can keep the login door closed and still invite.
 */
readonly class MagicLinkService
{
    /**
     * Minimum duration of a send decision, in microseconds.
     */
    protected int $floorMicroseconds;

    /**
     * @param  int|null  $floorMicroseconds  The decision floor; `security.auth_decision_floor_ms` unless given.
     */
    public function __construct(
        protected MagicLinkTokenHasher $hasher,
        protected TwoFactorChallengeService $challenges,
        protected SelfProvisioningService $provisioner,
        protected Timebox $timebox = new Timebox,
        ?int $floorMicroseconds = null,
    ) {
        $this->floorMicroseconds = $floorMicroseconds
            ?? 1000 * max((int) config('security.auth_decision_floor_ms', 500), 0);
    }

    /**
     * Issue a magic link for the given email, if it belongs to a usable user - or, with provisioning on, to no user at all, the consumed link creating the account.
     * Void in every case, so the HTTP response is identical for all of them.
     * Earlier links stay valid until their own TTL, so a delayed email does not strand the user.
     */
    public function send(string $email, ?string $redirect): void
    {
        if (!(bool) config('security.magic_link.enabled', true)) {
            return;
        }

        $this->timebox->call(function () use ($email, $redirect): void {
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

            // Scalars only, for the queue; a provisioning link goes to a bare address, there being no account yet.
            $notification = new MagicLinkNotification(
                url: $this->verificationUrl($plaintext, $redirect, provisioning: $user === null),
                expiresInMinutes: $ttlMinutes,
                userAgent: (string) request()->userAgent(),
                ipAddress: request()->ip(),
                requestedAt: now(),
                provisioning: $user === null,
            )->locale(app()->getLocale());

            if ($user === null) {
                Notification::route('mail', $email)->notify($notification);
            } else {
                $user->notify($notification);
            }
        }, $this->floorMicroseconds);
    }

    /**
     * Issue a first-sign-in invitation link for an admin-created account; the caller owns the feature gate and the
     * pending-state guard, this only mints and mails.
     *
     * Prior unconsumed invitations are revoked rather than left to their TTL: both sides of the exchange are known,
     * so a resend should leave exactly one live link.
     * No requesting-device line in the mail: it would name the admin's browser, nothing the recipient can judge.
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
     * The session-state guards run before the claim, so those outcomes never burn a still-valid token; everything
     * else runs after it, so a rejected token is spent either way: the account-state check, and each purpose's own
     * switch (`magic_link.enabled`, `invitations.enabled`), unknowable until the row is read, so outstanding links
     * of a disabled purpose die spent. Every token failure - unknown, expired, used, disabled - collapses into one
     * indistinguishable `invalidMagicLink` result.
     *
     * On success the session is regenerated against fixation and the email marked verified, the link having proved
     * the mailbox. Enrolled accounts are parked for the two-factor challenge instead: the link proves the mailbox,
     * not the second factor, and a compromised inbox alone must never become a takeover; the token is spent even if
     * the challenge is abandoned. A provisioning token creates its account here, where the mailbox is proven, unless
     * one with that email appeared since the send, in which case the link signs into it - the guarantee is the same.
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
     * The account a provisioning token signs into: the existing holder of the email if one appeared since the send,
     * otherwise a freshly provisioned one. Two links for the same email racing is settled by the unique index: the
     * loser's violation is caught and the winner's account re-resolved, the framework's createOrFirst() idiom.
     *
     * @return array{0: ?User, 1: bool} The account (null only when even the re-resolve finds nothing) and whether this call created it.
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
     * 32 bytes of CSPRNG (Cryptographically Secure Pseudorandom Number Generator) output, URL-safe base64 encoded (43 characters).
     */
    protected function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Build the SPA verification URL carried by the email.
     *
     * The redirect is forwarded only as an internal path (single leading slash), so a crafted request cannot turn
     * the email into an open redirect; the SPA validates it again before navigating. The `signup` and `invite`
     * markers let the verify page adapt its copy - cosmetic, ignored by consumption, and no leak, since they ride
     * inside the secret link, whose only reader the mail already told.
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
