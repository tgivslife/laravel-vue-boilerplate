<?php

namespace App\Console\Commands\Auth;

use App\Models\MagicLinkToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'auth:purge-magic-link-tokens')]
#[Description('Delete expired and consumed magic-link tokens')]
class PurgeMagicLinkTokensCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = MagicLinkToken::query()
            ->where('expires_at', '<=', now())
            ->orWhereNotNull('consumed_at')
            ->delete();

        $this->info("Purged {$deleted} magic-link tokens.");

        return self::SUCCESS;
    }
}
