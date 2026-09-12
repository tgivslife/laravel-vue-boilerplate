<?php

namespace App\Enums;

use App\Models\User;

/**
 * Where an account's two-factor enrollment stands: nothing minted, a secret awaiting confirmation, or an active factor.
 */
enum TwoFactorState
{
    case None;
    case Pending;
    case Active;

    public static function of(?User $user): self
    {
        return match (true) {
            $user === null, $user->two_factor_secret === null => self::None,
            $user->two_factor_confirmed_at === null => self::Pending,
            default => self::Active,
        };
    }
}
