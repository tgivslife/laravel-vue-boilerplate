<?php

namespace App\Console\Commands\Auth;

use App\Services\Auth\SessionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes session registry rows that can no longer belong to a live session, scheduled hourly.
 *
 * The registry only ever deletes here: listings leave dead rows out rather than delete them, since a session mid-rotation looks dead too.
 * The horizon is {@see SessionRegistry::staleMinutes()}, the session lifetime plus the registry's touch window: a row
 * untouched for that long cannot be a live session's, so nothing is asked of the session store.
 * Live rows age from their last activity, tombstones from their revocation - a copy of a revoked session written back late
 * lives a session lifetime from the revocation, whatever the row's activity said before.
 */
#[AsCommand(name: 'auth:purge-session-registry')]
#[Description('Delete session registry rows whose sessions have expired')]
class PurgeSessionRegistryCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $horizon = now()->subMinutes(SessionRegistry::staleMinutes());

        $deleted = DB::table('user_sessions')
            ->where(static function (Builder $query) use ($horizon): void {
                $query->whereNull('revoked_at')->where('last_activity', '<', $horizon->getTimestamp());
            })
            ->orWhere(static function (Builder $query) use ($horizon): void {
                $query->whereNotNull('revoked_at')->where('revoked_at', '<', $horizon);
            })
            ->delete();

        $this->info("Purged {$deleted} session registry entries.");

        return self::SUCCESS;
    }
}
