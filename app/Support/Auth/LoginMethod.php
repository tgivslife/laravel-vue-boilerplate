<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Context;

/**
 * The mechanism a login attempt came through.
 *
 * Each login door declares itself via {@see self::declare()} before authenticating; the authentication-log listeners
 * read the declaration when they record the attempt.
 * Stored as a plain string on authentication_logs.login_method - nullable, because rows that predate the column and
 * remember-me recaller re-logins carry no method.
 */
enum LoginMethod: string
{
    case Password = 'password';
    case MagicLink = 'magic_link';
    case Invitation = 'invitation';
    case Roeid = 'roeid';
    case Id = 'id';

    /** Laravel Context key carrying the current request's login method. */
    public const string CONTEXT_KEY = 'auth.login_method';

    /**
     * Declare the login method for the current request.
     */
    public function declare(): void
    {
        Context::add(self::CONTEXT_KEY, $this->value);
    }

    /**
     * The method declared for the current request, if any.
     */
    public static function declared(): ?string
    {
        return Context::get(self::CONTEXT_KEY);
    }
}
