<?php

namespace App\Support\Auth;

/**
 * Outcome of linking an external identity to the signed-in account (the OIDC callback's `connect` intent).
 */
enum IdentityLinkStatus
{
    /** The identity is now linked to the account. */
    case Linked;

    /** This exact identity was already linked to this account. */
    case AlreadyLinked;

    /** The identity is linked to a different account. */
    case SubjectTaken;

    /** The account already has a different identity for this provider. */
    case ProviderAlreadyLinked;
}
