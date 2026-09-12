<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a session the registry does not stand behind, before authentication can accept it.
 *
 * A revoked session can still reach the store: a request that was already running on it saves it back when it finishes,
 * under whatever id it held - the current one, or one a rotation has since replaced.
 * The registry keeps a tombstone on the session's row, which every copy names from its payload, so the next request
 * on any such copy is signed out here and given a fresh guest session.
 * A session naming a row that is gone is signed out the same way: rows only go when their session is dead.
 * So is a signed-in session naming no row at all, which the registry never recorded and no revocation could reach.
 * Placed ahead of authentication in the middleware priority list, since the auth middleware would otherwise resolve the user first.
 *
 * Checked again after the response for a request that rotated its id (a swap): revoked while it ran, it would
 * otherwise save the session under a new id, and its browser would present that id for the next request.
 */
readonly class EnsureSessionRegistered
{
    public function __construct(private SessionRegistry $registry)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authenticated($request)
            && ($this->registry->isRevoked($request) || $this->unregistered($request))) {
            $this->registry->signOut($request);
        }

        $response = $next($request);

        if ($this->authenticated($request) && $this->registry->rotatedFromRevoked($request)) {
            $this->registry->signOut($request);
        }

        return $response;
    }

    private function authenticated(Request $request): bool
    {
        return $request->hasSession() && $request->user('web') !== null;
    }

    /**
     * Whether the session holds a sign-in the registry never recorded: the guard's login marker, but no row id.
     *
     * Read off the payload rather than the resolved user, so a user set on the guard without a session sign-in (a test's actingAs) is left alone.
     * A session the remember-me cookie has just restored is exempt: the guard wrote the marker into a fresh session during this request,
     * and registration follows once the response is produced.
     */
    private function unregistered(Request $request): bool
    {
        $guard = Auth::guard('web');

        return $guard instanceof SessionGuard
            && $this->registry->currentRowId($request) === null
            && $request->session()->has($guard->getName())
            && !$guard->viaRemember();
    }
}
