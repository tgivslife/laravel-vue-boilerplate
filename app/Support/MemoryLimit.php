<?php

namespace App\Support;

/**
 * Raises the PHP memory limit for a workload that outgrows the 128 MB CLI/worker default (large imports, bulk
 * reconciliations), but only ever raises it, never lowering a roomier or unlimited one.
 */
class MemoryLimit
{
    public static function ensureMegabytes(int $megabytes): void
    {
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && self::bytes($limit) < $megabytes * 1048576) {
            ini_set('memory_limit', "{$megabytes}M");
        }
    }

    private static function bytes(string $limit): float
    {
        $value = (float) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => $value,
        };
    }
}
