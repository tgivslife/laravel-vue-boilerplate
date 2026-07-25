<?php

namespace App\Console\Commands\Audit;

use App\Models\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'audit:purge-logs')]
#[Description('Delete attribute-level audit entries older than the configured retention period')]
class PurgeAuditsCommand extends Command
{
    /**
     * Execute the console command.
     *
     * The trail records attribute values verbatim (which may include PII), so it must not grow forever,
     * but an audit trail is also the kind of record some deployments must keep indefinitely, so a non-positive retention
     * disables pruning instead of deleting everything.
     */
    public function handle(): int
    {
        $retentionDays = (int) config('audit.retention_days', 730);

        if ($retentionDays <= 0) {
            $this->info('Audit retention is unlimited; nothing purged.');

            return self::SUCCESS;
        }

        $deleted = Audit::query()
            ->where('created_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $this->info("Purged {$deleted} audit entries.");

        return self::SUCCESS;
    }
}
