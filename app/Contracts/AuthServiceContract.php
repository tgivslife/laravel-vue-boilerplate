<?php

namespace App\Contracts;

use App\Http\Payloads\Auth\LoginPayload;
use App\Support\Auth\LoginResult;
use Illuminate\Http\Request;

/**
 * Defines the contract for authentication services.
 *
 * Implementations handle the full login and logout lifecycle
 * for a specific authentication strategy (e.g. API token, session).
 * The contract returns domain objects rather than HTTP responses, keeping transport concerns out of the service layer.
 */
interface AuthServiceContract
{
    /**
     * Attempt to authenticate a user with the given credentials.
     *
     * Returns a LoginResult describing the outcome - success or a specific
     * failure reason - without throwing exceptions for expected failure cases.
     */
    public function login(LoginPayload $loginPayload): LoginResult;

    /**
     * Terminate the current authenticated session or revoke the active token.
     */
    public function logout(Request $request): void;
}
