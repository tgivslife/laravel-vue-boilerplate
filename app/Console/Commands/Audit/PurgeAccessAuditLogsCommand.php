<?php

namespace App\Console\Commands\Audit;

use App\Models\Access\AccessAuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'access:purge-audit-logs')]
#[Description('Delete access audit entries older than the configured retention period')]
class PurgeAccessAuditLogsCommand extends Command
{
    /**
     * Execute the console command.
     *
     * The audit trail holds PII on purpose (before-snapshots retain original emails past the
     * tombstone), so it must not grow forever - but an audit trail is also the kind of record
     * some deployments must keep indefinitely, so a non-positive retention disables pruning
     * instead of deleting everything.
     */
    public function handle(): int
    {
        $retentionDays = (int) config('access.audit_log.retention_days', 730);

        if ($retentionDays <= 0) {
            $this->info('Audit log retention is unlimited; nothing purged.');

            return self::SUCCESS;
        }

        $deleted = AccessAuditLog::query()
            ->where('created_at', '<=', now()->subDays($retentionDays))
            ->delete();

        $this->info("Purged {$deleted} access audit entries.");

        return self::SUCCESS;
    }
}
