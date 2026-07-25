<?php

namespace App\Helpers;

class Utils
{
    /**
     * Convert a number of seconds into a human-readable string.
     *
     * @param  int  $seconds
     * @return string  e.g. "1 hour, 30 minutes, 5 seconds"
     */
    public static function secondsToHuman(int $seconds): string
    {
        $parts = [];

        $units = [
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
            'second' => 1,
        ];

        foreach ($units as $label => $value) {
            $count = intdiv($seconds, $value);
            if ($count > 0) {
                $parts[] = "{$count} {$label}".($count > 1 ? 's' : '');
                $seconds %= $value;
            }
        }

        return implode(', ', $parts) ?: '0 seconds';
    }
}
