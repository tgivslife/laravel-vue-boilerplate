<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Cache\RedisStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SessionHandlerInterface;

/**
 * App-owned index of which sessions belong to which user.
 *
 * The sessions are stored as opaque records with no user index (redis cannot be enumerated per user), so every
 * feature that lists or revokes a user's sessions goes through this registry instead of the driver's storage.
 * Rows are written by {@see \App\Http\Middleware\RecordSessionActivity} on authenticated requests,
 * removed on logout and revocation, pruned lazily on read when their underlying session no longer exists,
 * and swept by auth:purge-session-registry once they outlive the session lifetime.
 *
 * Revocation always destroys the real session through the configured driver's handler,
 * so it works identically on database, redis, or any other backend.
 */
readonly class SessionRegistry
{
    public function __construct(protected SessionManager $sessions)
    {
    }

    /**
     * Minutes of registry inactivity after which a row is guaranteed dead.
     *
     * A session cannot outlive `session.lifetime` minutes of inactivity, and a live session's registry row is refreshed
     * at least every `touch_minutes` - so a row untouched for the two combined cannot belong to a live session and
     * may be deleted without consulting the driver.
     */
    public static function staleMinutes(): int
    {
        return (int) config('session.lifetime', 120)
            + (int) config('security.session_registry.touch_minutes', 5);
    }

    /**
     * Record the current request's session, throttled.
     *
     * Writes only when the session is unseen or its recorded activity is older than the configured window,
     * so the registry does not add a write to every request the way the session store itself does.
     */
    public function record(Request $request): void
    {
        /*
         * Resolved through the session (web) guard, not the request's default sanctum guard;
         * sanctum's RequestGuard caches the user it authenticated with and keeps returning it after logout() or
         * account deletion, which would re-register the fresh guest session under the departed user.
         * The web guard reflects in-request logouts, so only sessions that END the request authenticated are recorded.
         */
        $user = $request->user('web');

        if ($user === null || !$request->hasSession()) {
            return;
        }

        $sessionId = $request->session()->getId();
        $now = now()->getTimestamp();
        $staleBefore = $now - 60 * (int) config('security.session_registry.touch_minutes', 5);

        $existing = $this->table()->where('session_id', $sessionId)->first();

        if ($existing === null) {
            $this->table()->insert([
                'user_id' => $user->getAuthIdentifier(),
                'session_id' => $sessionId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_activity' => $now,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((int) $existing->last_activity < $staleBefore) {
            $this->table()->where('session_id', $sessionId)->update([
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_activity' => $now,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Drop a session's registry row without touching the session itself
     * (used on logout, where the framework already invalidates it).
     */
    public function forget(string $sessionId): void
    {
        $this->table()->where('session_id', $sessionId)->delete();
    }

    /**
     * The user's live sessions, most recently active first.
     *
     * Rows whose underlying session no longer exists (expired, logged out elsewhere) are pruned on the way out,
     * so the caller only ever sees sessions that are actually alive.
     *
     * @return Collection<int, object>
     */
    public function forUser(User $user): Collection
    {
        // Rows past the liveness horizon cannot belong to a live session;
        // one indexed delete spares a driver round trip per row that expired since the last sweep.
        $this->table()
            ->where('user_id', $user->getKey())
            ->where('last_activity', '<', now()->subMinutes(static::staleMinutes())->getTimestamp())
            ->delete();

        $rows = $this->table()
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get();

        $liveIds = $this->liveSessionIds($rows->pluck('session_id'));

        [$live, $dead] = $rows->partition(
            static fn(object $row): bool => isset($liveIds[(string) $row->session_id])
        );

        if ($dead->isNotEmpty()) {
            $this->table()->whereIn('id', $dead->pluck('id'))->delete();
        }

        return $live->values();
    }

    /**
     * Which of the given session ids still exist in the session store, as a set.
     *
     * Existence is all the registry needs, so the redis and database backends are checked in a single round trip
     * (pipelined EXISTS / one whereIn) with no payload transfer; other drivers fall back to reading each session through the handler.
     * A Redis Cluster cannot route one pipeline across hash slots, so there the EXISTS calls go out one by one,
     * bounded per user by the registry's own row pruning, so the extra round trips stay small.
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
     * Destroy one of the user's sessions and drop its registry row.
     */
    public function destroy(User $user, string $sessionId): void
    {
        $owned = $this->table()
            ->where('user_id', $user->getKey())
            ->where('session_id', $sessionId)
            ->exists();

        if (!$owned) {
            return;
        }

        $this->handler()->destroy($sessionId);
        $this->forget($sessionId);
    }

    /**
     * Destroy every session of the user except the given one.
     */
    public function destroyOthers(User $user, string $currentSessionId): void
    {
        $this->destroyWhere($user, fn($query) => $query->where('session_id', '!=', $currentSessionId));
    }

    /**
     * Destroy every session of the user.
     *
     * Belt and braces for the database driver: sessions created before the registry shipped (or that somehow escaped it)
     * have rows the registry does not know about, so the driver's own table is swept as well.
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
     * Destroy the registered sessions matching the given constraint.
     *
     * @param  callable(Builder): Builder  $constrain
     */
    private function destroyWhere(User $user, callable $constrain): void
    {
        $handler = $this->handler();

        $sessionIds = $constrain($this->table()->where('user_id', $user->getKey()))
            ->pluck('session_id');

        foreach ($sessionIds as $sessionId) {
            $handler->destroy((string) $sessionId);
        }

        if ($sessionIds->isNotEmpty()) {
            $this->table()->whereIn('session_id', $sessionIds)->delete();
        }
    }

    private function table(): Builder
    {
        return DB::table('user_sessions');
    }

    /**
     * The configured session driver's raw handler.
     * Reads on foreign ids are safe here: every caller runs on a session that already exists,
     * so the database handler's `exists` side effect cannot break the save.
     */
    private function handler(): SessionHandlerInterface
    {
        return $this->sessions->driver()->getHandler();
    }
}
