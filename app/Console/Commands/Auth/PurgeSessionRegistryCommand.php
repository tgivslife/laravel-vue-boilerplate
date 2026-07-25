<?php

namespace App\Console\Commands\Auth;

use App\Services\Auth\SessionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'auth:purge-session-registry')]
#[Description('Delete session registry rows whose sessions have expired')]
class PurgeSessionRegistryCommand extends Command
{
    /**
     * Execute the console command.
     *
     * Sweeps rows past the liveness horizon shared with {@see SessionRegistry::staleMinutes()}.
     */
    public function handle(): int
    {
        $deleted = DB::table('user_sessions')
            ->where('last_activity', '<', now()->subMinutes(SessionRegistry::staleMinutes())->getTimestamp())
            ->delete();

        $this->info("Purged {$deleted} session registry entries.");

        return self::SUCCESS;
    }
}
