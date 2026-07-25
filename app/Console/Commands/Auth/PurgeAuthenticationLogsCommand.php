<?php

namespace App\Console\Commands\Auth;

use App\Models\AuthenticationLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'auth:purge-authentication-logs')]
#[Description('Delete authentication log entries older than the configured retention period')]
class PurgeAuthenticationLogsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = (int) config('security.authentication_log.retention_days', 365);

        $deleted = AuthenticationLog::query()
            ->where('login_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $this->info("Purged {$deleted} authentication log entries.");

        return self::SUCCESS;
    }
}
