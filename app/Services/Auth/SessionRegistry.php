<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Access\ImpersonationService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RedisStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use SessionHandlerInterface;
use Throwable;

/**
 * App-owned index of which sessions belong to which user.
 *
 * Session drivers store opaque records with no per-user index, so listing and revoking a user's sessions goes through this registry.
 * Rows are written by RegisterSession on authenticated requests (a borrowed session under the impersonating admin),
 * removed by the sign-out paths, left out of listings when the session is gone, and swept by auth:purge-session-registry
 * past the session lifetime.
 *
 * The row is the session's identity: the session carries the row id in its payload (SESSION_KEY), so the row is found
 * again after the id rotates (an impersonation swap) and on any copy of the session written back under an old id.
 * A session without the key is new to the registry, whatever cookie it arrived on.
 *
 * Revocation destroys the session through the driver's handler, the same on every backend, and keeps the row as a
 * tombstone (revoked_at): a request already running on the session writes it back when it finishes, and the tombstone
 * is what gets that copy signed out instead of taken for a new session. A row that is gone counts the same, since
 * rows only go when their session is dead. The registry is what revocation runs on, so its writes are not optional:
 * a session it could not register is signed out, and a sign-out whose row it could not drop fails.
 */
readonly class SessionRegistry
{
    /**
     * Session key holding the id of the session's registry row.
     */
    public const string SESSION_KEY = 'session_registry_id';

    public function __construct(protected SessionManager $sessions, protected ImpersonationService $impersonation)
    {
    }

    /**
     * Minutes of registry inactivity after which a row is guaranteed dead.
     *
     * A session cannot outlive `session.lifetime` minutes of inactivity, and a live session's registry row is refreshed
     * at least every `touch_minutes` - so a row untouched for the two combined cannot belong to a live session and may be deleted without consulting the driver.
     */
    public static function staleMinutes(): int
    {
        return (int) config('session.lifetime', 120)
            + (int) config('security.session_registry.touch_minutes', 5);
    }

    /**
     * Register the current request's session, or keep its registration current.
     *
     * Registering an unseen session and moving the row after a rotation throw on failure, for the caller to sign
     * the session out: unregistered, it is beyond every revocation; unmoved, it drops out of listings for good.
     * The activity refresh is best-effort and throttled to the configured window, so the registry does not add a
     * write to every request the way the session store does.
     *
     * @throws Throwable When the session cannot be registered or its row moved.
     */
    public function register(Request $request): void
    {
        $ownerId = $this->ownerId($request);

        if ($ownerId === null) {
            return;
        }

        $session = $request->session();
        $sessionId = $session->getId();
        $now = now()->getTimestamp();

        $rowId = $this->currentRowId($request);
        $row = $rowId === null ? null : $this->table()->where('id', $rowId)->first();

        // Revoked, or its row gone: never re-registered here; the request that meets it is signed out instead.
        if ($rowId !== null && ($row === null || $row->revoked_at !== null)) {
            return;
        }

        // Another owner is a fresh sign-in on a kept payload: its rotation killed the row's session, so the row goes too.
        if ($row !== null && (string) $row->user_id !== (string) $ownerId) {
            $this->table()->where('id', $row->id)->whereNull('revoked_at')->delete();
            $row = null;
        }

        if ($row === null) {
            $rowId = $this->table()->insertGetId([
                'user_id' => $ownerId,
                'session_id' => $sessionId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'remembered' => $this->isRemembered($request),
                'last_activity' => $now,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $session->put(self::SESSION_KEY, $rowId);

            return;
        }

        $activity = [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_activity' => $now,
            'updated_at' => now(),
        ];

        // Only this request's own rotation moves the row: a copy written back late under an old id must not drag it back.
        if ($this->presentedSessionId($request) !== null) {
            $this->table()->where('id', $row->id)->whereNull('revoked_at')->update([
                'session_id' => $sessionId,
                ...$activity,
            ]);

            return;
        }

        $staleBefore = $now - 60 * (int) config('security.session_registry.touch_minutes', 5);

        if ((int) $row->last_activity >= $staleBefore) {
            return;
        }

        rescue(fn() => $this->table()->where('id', $row->id)->whereNull('revoked_at')->update($activity), report: true);
    }

    /**
     * Sign the request's session out on this device.
     *
     * This browser only: the remember token stays, a revocation rotates it where that is due.
     * The remember cookie comes off the request as well as the response, or the fresh guard built below would read it straight back in.
     * The session is invalidated under a fresh id, so the store's copy is destroyed rather than saved again,
     * and the guards are dropped because Sanctum's resolved the user before this ran and would keep answering with it.
     */
    public function signOut(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $guard = Auth::guard('web');
        $guard->logoutCurrentDevice();

        if ($guard instanceof SessionGuard) {
            $request->cookies->remove($guard->getRecallerName());
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();
    }

    /**
     * Whose session the current request's is, or null for a guest.
     *
     * Through the web guard, which reflects an in-request logout; sanctum's guard would still answer with the user it authenticated.
     * A borrowed session (impersonation) is the admin's browser, so it is filed under the admin.
     */
    private function ownerId(Request $request): int|string|null
    {
        if (!$request->hasSession() || $request->user('web') === null) {
            return null;
        }

        return $this->impersonation->state($request)['actor_id']
            ?? $request->user('web')->getAuthIdentifier();
    }

    /**
     * Whether the browser behind the request holds a remember-me cookie, so its revocation knows to rotate the token.
     *
     * On the sign-in that mints the cookie it is only queued on the response, so the queue is checked too.
     */
    private function isRemembered(Request $request): bool
    {
        $guard = Auth::guard('web');

        if (!$guard instanceof SessionGuard) {
            return false;
        }

        $recaller = $guard->getRecallerName();

        return $request->cookies->has($recaller) || Cookie::hasQueued($recaller);
    }

    /**
     * Whether the request's session was revoked: the row it names is a tombstone or gone, so the copy the request
     * runs on is a late write from a request that ran through the revocation. A session naming no row is not revoked.
     */
    public function isRevoked(Request $request): bool
    {
        $rowId = $this->currentRowId($request);

        return $rowId !== null && !$this->table()->where('id', $rowId)->whereNull('revoked_at')->exists();
    }

    /**
     * Whether the request rotated its session id (a swap) and its session was revoked meanwhile: the new id carries
     * the old session's payload and its row id with it, and the tombstone is on that row.
     */
    public function rotatedFromRevoked(Request $request): bool
    {
        return $this->presentedSessionId($request) !== null && $this->isRevoked($request);
    }

    /**
     * The registry row id carried in the request's session payload: which row is the request's own session.
     * Null for a session the registry has not recorded.
     */
    public function currentRowId(Request $request): ?int
    {
        if (!$request->hasSession()) {
            return null;
        }

        $rowId = $request->session()->get(self::SESSION_KEY);

        return is_int($rowId) ? $rowId : null;
    }

    /**
     * The session id the browser presented, when the request has since rotated to a different one.
     */
    private function presentedSessionId(Request $request): ?string
    {
        $presented = $request->cookies->get((string) config('session.cookie'));

        return is_string($presented) && $request->hasSession() && $presented !== $request->session()->getId()
            ? $presented
            : null;
    }

    /**
     * Drop the row of the request's own session, as the first step of a sign-out.
     *
     * Before anything local changes, and unrescued: a row left live would take a late write of the session back as
     * signed in, so a sign-out whose row cannot be dropped must fail with nothing changed, for the user to retry.
     *
     * @throws Throwable When the row cannot be dropped.
     */
    public function forgetCurrent(Request $request): void
    {
        $rowId = $this->currentRowId($request);

        if ($rowId !== null) {
            $this->forget($rowId);
        }
    }

    /**
     * Drop a registry row without touching its session. Tombstones stay: a sign-out arriving late on a revoked
     * session must not clear the way for a late write.
     */
    public function forget(int $rowId): void
    {
        $this->table()->where('id', $rowId)->whereNull('revoked_at')->delete();
    }

    /**
     * The user's live sessions, most recently active first.
     *
     * Rows whose session is missing from the store are left out, not deleted: a session mid-rotation is missing too,
     * between its old id being destroyed and its new one saved, and deleting its row then would leave a live session unregistered.
     * Deleting is the sweep's job, past the liveness horizon.
     *
     * @return Collection<int, object>
     */
    public function forUser(User $user): Collection
    {
        // Rows past the liveness horizon are dead: one indexed delete spares a driver round trip each. Tombstones stay for the sweep.
        $this->table()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where('last_activity', '<', now()->subMinutes(static::staleMinutes())->getTimestamp())
            ->delete();

        $rows = $this->table()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('last_activity')
            ->get();

        $liveIds = $this->liveSessionIds($rows->pluck('session_id'));

        return $rows
            ->filter(static fn(object $row): bool => isset($liveIds[(string) $row->session_id]))
            ->values();
    }

    /**
     * Which of the given session ids still exist in the session store, as a set.
     *
     * Redis and database backends answer in one round trip (pipelined EXISTS, one whereIn) with no payload transfer;
     * a Redis Cluster cannot route one pipeline across hash slots, so its EXISTS calls go out one by one; other
     * drivers read each session through the handler.
     *
     * @param  Collection<int, mixed>  $sessionIds
     * @return array<string, int|true>
     */
    private function liveSessionIds(Collection $sessionIds): array
    {
        $sessionIds = $sessionIds->map(static fn(mixed $id): string => (string) $id)->values();

        if ($sessionIds->isEmpty()) {
            return [];
        }

        $handler = $this->handler();

        if ($handler instanceof CacheBasedSessionHandler
            && ($store = $handler->getCache()->getStore()) instanceof RedisStore) {
            $prefix = $store->getPrefix();
            $connection = $store->connection();

            if ($connection instanceof PhpRedisClusterConnection || $connection instanceof PredisClusterConnection) {
                return $sessionIds
                    ->filter(static fn(string $id): bool => (bool) $connection->exists($prefix.$id))
                    ->flip()
                    ->all();
            }

            $flags = $connection->pipeline(
                static function ($pipe) use ($sessionIds, $prefix): void {
                    foreach ($sessionIds as $sessionId) {
                        $pipe->exists($prefix.$sessionId);
                    }
                }
            );

            return $sessionIds
                ->filter(static fn(string $id, int $index): bool => (bool) ($flags[$index] ?? false))
                ->flip()
                ->all();
        }

        if (config('session.driver') === 'database') {
            return DB::table((string) config('session.table', 'sessions'))
                ->whereIn('id', $sessionIds)
                ->pluck('id')
                ->flip()
                ->all();
        }

        return $sessionIds
            ->filter(static fn(string $id): bool => $handler->read($id) !== '')
            ->flip()
            ->all();
    }

    /**
     * Destroy one of the user's sessions, addressed by its registry row, leaving the row as a tombstone.
     *
     * By row rather than session id: the session may have rotated since the caller listed it, and every copy of it
     * names the row, so the tombstone reaches the current id and any old id a late write brings back.
     */
    public function destroy(User $user, int $rowId): void
    {
        $this->destroyWhere($user, static fn(Builder $query): Builder => $query->where('id', $rowId));
    }

    /**
     * Destroy every session of the user except the one the request runs on, spared by its row, whichever id it holds.
     */
    public function destroyOthers(User $user, Request $request): void
    {
        $currentRowId = $this->currentRowId($request);

        $this->destroyWhere($user, static fn(Builder $query): Builder => $currentRowId === null
            ? $query
            : $query->where('id', '!=', $currentRowId));
    }

    /**
     * Destroy every session of the user; on the database driver, the driver's own table is swept as well, in case a
     * session escaped the registry.
     */
    public function destroyAll(User $user): void
    {
        $this->destroyWhere($user, static fn($query) => $query);

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }

    /**
     * Destroy the user's live registered sessions matching the given constraint, leaving their rows as tombstones.
     *
     * The tombstone lands first: from then on no logout deletes the row, no rotation moves it, and no late write is taken for a new session.
     * The session ids are read back after the mark, since a rotation may have moved a row since the selection, and only then destroyed in the driver.
     *
     * @param  callable(Builder): Builder  $constrain
     */
    private function destroyWhere(User $user, callable $constrain): void
    {
        $rowIds = $constrain($this->table()->where('user_id', $user->getKey())->whereNull('revoked_at'))
            ->pluck('id');

        if ($rowIds->isEmpty()) {
            return;
        }

        $this->table()->whereIn('id', $rowIds)->whereNull('revoked_at')->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        $handler = $this->handler();

        foreach ($this->table()->whereIn('id', $rowIds)->pluck('session_id') as $sessionId) {
            $handler->destroy((string) $sessionId);
        }
    }

    private function table(): Builder
    {
        return DB::table('user_sessions');
    }

    /**
     * The configured session driver's raw handler. Reading foreign ids through it is safe: every caller runs on a
     * session that already exists, so the database handler's `exists` side effect cannot break the save.
     */
    private function handler(): SessionHandlerInterface
    {
        return $this->sessions->driver()->getHandler();
    }
}
