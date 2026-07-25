<?php

namespace App\Support\Auth;

/**
 * Outcome of an OIDC callback's attempt to map the validated identity onto a local session.
 *
 * Deliberately separate from LoginStatus: the callback communicates by redirect, not by the JSON LoginResponse pipeline,
 * and its outcomes are a different, smaller set.
 */
enum IdentityLoginStatus
{
    /**
     * The identity resolved to a usable account and a session is open.
     */
    case Success;

    /**
     * No local account claims this identity, and the provider's link policy did not allow
     * creating the link (or the account) on the fly.
     */
    case NotLinked;

    /**
     * The identity is linked, but its account is deactivated, banned or gone.
     * Surfaced only after the identity verified, mirroring the post-credential account-state gate of the other login doors.
     */
    case AccountUnavailable;

    /**
     * The identity verified, but the app-side second factor is still owed.
     */
    case TwoFactorRequired;
}
