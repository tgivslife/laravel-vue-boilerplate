<?php

namespace App\Support\Auth;

/**
 * Represents all possible outcomes of a login attempt.
 *
 * Used as the status field in LoginResult and matched against in LoginResponse
 * to determine the appropriate HTTP response.
 */
enum LoginStatus: string
{
    /** Credentials were valid and the user is now authenticated. */
    case Success = 'success';

    /** The user is already authenticated and cannot log in again. */
    case AlreadyAuthenticated = 'already_authenticated';

    /** The provided email/password combination did not match any user. */
    case InvalidCredentials = 'invalid_credentials';

    /** The user exists but has not yet verified their email address. */
    case EmailNotVerified = 'email_not_verified';

    /** The user's account has been deactivated by an administrator. */
    case AccountDeactivated = 'account_deactivated';

    /** The account is temporarily locked due to too many failed login attempts. */
    case AccountLocked = 'account_locked';

    /** A second authentication factor is required before access is granted. */
    case TwoFactorRequired = 'two_factor_required';

    /** The submitted two-factor code (TOTP or recovery) did not verify. */
    case InvalidTwoFactorCode = 'invalid_two_factor_code';

    /** No two-factor challenge is pending, or the pending one timed out. */
    case TwoFactorChallengeExpired = 'two_factor_challenge_expired';

    /** The request has no session store attached, so session-based login cannot proceed. */
    case SessionUnavailable = 'session_unavailable';

    /** The presented magic-link token was unknown, expired, or already used. */
    case InvalidMagicLink = 'invalid_magic_link';
}
