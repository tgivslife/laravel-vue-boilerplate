<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RuntimeException;

/**
 * Rejects passwords that appear in the committed list of commonly used passwords.
 *
 * The list (`resources/security/common-passwords.txt`, from SecLists' xato-net top 100k) is normalized to lowercase,
 * deduplicated and byte-order sorted at generation time, so membership is a strcmp binary search over the file,
 * no memory load, no network, microseconds per check. Refreshing the list is a deliberate git commit, never a runtime download.
 *
 * Candidates are checked lowercased, and again with the trailing digits/punctuation people bolt onto a weak
 * base ("Password2026!" -> "password"), since the policy's minimum length pushes exactly that habit.
 */
class NotCommonPassword implements ValidationRule
{
    /**
     * Trailing characters stripped to expose the base word behind "word + year + symbol" passwords.
     */
    private const string DECORATION_CHARACTERS = '0123456789!@#$%^&*()_+-=.,?';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $lowered = mb_strtolower($value);
        $base = rtrim($lowered, self::DECORATION_CHARACTERS);

        $candidates = array_filter(
            array_unique([$lowered, $base]),
            static fn(string $candidate): bool => mb_strlen($candidate) >= 4,
        );

        foreach ($candidates as $candidate) {
            if ($this->listContains($candidate)) {
                $fail('validation.password.common')->translate();

                return;
            }
        }
    }

    /**
     * Binary search the sorted list file for an exact line match.
     *
     * Seeks to the middle of the remaining byte range, discards the partial line the seek landed in, and
     * compares the next full line - the classic sorted-flat-file search, O(log n) reads on a cold file cache.
     */
    private function listContains(string $needle): bool
    {
        $path = resource_path((string) config('security.password_policy.common_list', 'security/common-passwords.txt'));

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Common password list missing or unreadable at [{$path}].");
        }

        try {
            $size = (int) filesize($path);

            if ($size === 0) {
                throw new RuntimeException("Common password list at [{$path}] is empty - the policy would accept every password.");
            }

            $low = 0;
            $high = $size;

            while ($low < $high) {
                $middle = intdiv($low + $high, 2);

                fseek($handle, $middle);

                if ($middle > 0) {
                    fgets($handle);
                }

                $lineStart = ftell($handle);
                $line = fgets($handle);

                if ($line === false) {
                    $high = $middle;

                    continue;
                }

                $comparison = strcmp($needle, rtrim($line, "\r\n"));

                if ($comparison === 0) {
                    return true;
                }

                if ($comparison < 0) {
                    $high = $middle;
                } else {
                    $low = $lineStart + strlen($line);
                }
            }

            /*
             * $low only ever advances to line starts, so at exit it points at the first line >= the
             * needle - which the loop itself can never have compared: any probe landing inside that
             * line discards it as a partial read. One direct look settles it.
             */
            if ($low < $size) {
                fseek($handle, $low);
                $line = fgets($handle);

                return $line !== false && rtrim($line, "\r\n") === $needle;
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}
