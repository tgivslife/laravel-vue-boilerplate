<?php

namespace App\Support\Auth;

use App\Http\Resources\AuthenticationLogEntryResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * One page of a user's authentication log - the shared read behind the settings history and the admin user detail page,
 * so the ordering, the day-window semantics and the payload shape are defined exactly once.
 */
class AuthenticationLogPage
{
    /**
     * Build the {entries, has_more} payload, newest first, optionally narrowed to a single day.
     *
     * Entries are ordered by login_at with id as tiebreaker - login_at has second precision (and is nullable),
     * so without it rows could shift across page boundaries between requests.
     *
     * simplePaginate keeps it to one cheap query per page; the client only needs the rows and an "is there more" flag.
     *
     * @return array{entries: AnonymousResourceCollection, has_more: bool}
     */
    public static function for(User $user, ?string $date): array
    {
        $query = $user->authentications()
            ->orderByDesc('login_at')
            ->orderByDesc('id');

        if ($date !== null) {
            $day = Carbon::createFromFormat('Y-m-d', $date, config('app.timezone'))->startOfDay();

            $query->whereBetween('login_at', [$day, $day->clone()->endOfDay()]);
        }

        $entries = $query->simplePaginate((int)config('security.authentication_log.page_size', 15));

        return [
            'entries' => AuthenticationLogEntryResource::collection($entries->items()),
            'has_more' => $entries->hasMorePages(),
        ];
    }
}
