<?php

namespace App\Console\Commands\Ops;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Verifies the committed common-passwords list upholds what NotCommonPassword's binary search silently assumes:
 * byte-sorted (LC_ALL=C order), lowercase, LF-only, no blanks, no duplicates.
 *
 * A list violating any of these does not error at runtime - the search just stops finding entries, quietly weakening the password policy.
 * Run this manually after editing or regenerating the list, before committing it; pass a path to vet a candidate file that
 * has not replaced the configured list yet.
 */
#[Signature('security:verify-password-list {path? : File to verify instead of the configured list}')]
#[Description('Verify the common-passwords list is byte-sorted, lowercase and LF-only')]
class VerifyPasswordListCommand extends Command
{
    private const int MAX_REPORTED_VIOLATIONS = 10;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = (string) ($this->argument('path')
            ?? resource_path((string) config('security.password_policy.common_list', 'security/common-passwords.txt')));

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->error("List missing or unreadable at [{$path}].");

            return self::FAILURE;
        }

        $violations = [];
        $lineNumber = 0;
        $previous = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $entry = rtrim($line, "\n");

                if (str_contains($entry, "\r")) {
                    $violations[] = "line {$lineNumber}: CRLF line ending";
                    $entry = rtrim($entry, "\r");
                }

                if ($entry === '') {
                    $violations[] = "line {$lineNumber}: blank line";

                    continue;
                }

                if ($entry !== mb_strtolower($entry)) {
                    $violations[] = "line {$lineNumber}: not lowercase [{$entry}]";
                }

                if ($previous !== null && strcmp($previous, $entry) >= 0) {
                    $violations[] = strcmp($previous, $entry) === 0
                        ? "line {$lineNumber}: duplicate of the previous line [{$entry}]"
                        : "line {$lineNumber}: out of byte order [{$previous}] > [{$entry}]";
                }

                $previous = $entry;
            }
        } finally {
            fclose($handle);
        }

        if ($lineNumber === 0) {
            $this->error("List at [{$path}] is empty - every password would pass the common-password check.");

            return self::FAILURE;
        }

        if ($violations !== []) {
            foreach (array_slice($violations, 0, self::MAX_REPORTED_VIOLATIONS) as $violation) {
                $this->error($violation);
            }

            if (count($violations) > self::MAX_REPORTED_VIOLATIONS) {
                $this->error(sprintf('... and %d more.', count($violations) - self::MAX_REPORTED_VIOLATIONS));
            }

            $this->error(sprintf(
                'List at [%s] has %d violation(s). Regenerate with: tr -d "\r" | tr "[:upper:]" "[:lower:]" | LC_ALL=C grep -v "^$" | LC_ALL=C sort -u',
                $path,
                count($violations),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('List at [%s] is valid: %d entries, sorted, lowercase, LF-only.', $path, $lineNumber));

        return self::SUCCESS;
    }
}
