<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\Config;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\AuditDriver;
use OwenIt\Auditing\Models\Audit as DefaultAudit;

/**
 * Holds audit records in memory for the length of one bulk write and stores them in batched inserts, instead of the package driver's insert-per-record.
 *
 * That driver writes each record through withSavepointIfNeeded(), so inside an open transaction every record opens a SAVEPOINT;
 * Postgres holds a transactionid lock per subtransaction until the outer transaction commits, and the shared lock table
 * caps those at max_locks_per_transaction * max_connections - a bulk write auditing one row per record runs out of locks mid-run.
 * Buffering costs one subtransaction however many records are written.
 *
 * Skipped per buffered record: pruning to audit.threshold (a no-op at threshold 0) and the Audited event (nothing listens for it).
 */
class BufferedAuditDriver implements AuditDriver
{
    /**
     * Records held before an insert - bounded because holding a whole run's records would cost more memory than it has, and storing early costs nothing: an insert is not a subtransaction.
     */
    private const int BATCH = 500;

    /**
     * @var list<array<string, mixed>>
     */
    private static array $buffered = [];

    private static bool $collecting = false;

    /**
     * Runs $work with audit records buffered, stores them, and returns $work's value.
     *
     * The inserts land in the caller's open transaction, so the records commit with the writes they describe.
     * Call it inside one: a throw discards what is still buffered, but batches already stored need the caller's rollback,
     * or they describe writes that did not survive.
     */
    public static function collect(callable $work): mixed
    {
        if (self::$collecting) {
            return $work();
        }

        $enclosing = Config::get('audit.driver');
        Config::set('audit.driver', self::class);
        self::$collecting = true;

        try {
            $result = $work();
            self::store();

            return $result;
        } finally {
            self::$collecting = false;
            self::$buffered = [];
            Config::set('audit.driver', $enclosing);
        }
    }

    /**
     * Buffers the record and reports that none was stored, which is what stops the package from pruning and dispatching for a record that does not exist yet.
     */
    public function audit(Auditable $model): ?Audit
    {
        $now = now();

        // Stamped per record rather than at the insert: the trail keeps the time of each write.
        self::$buffered[] = [...$model->toAudit(), 'created_at' => $now, 'updated_at' => $now];

        if (count(self::$buffered) >= self::BATCH) {
            self::store();
        }

        return null;
    }

    public function prune(Auditable $model): bool
    {
        return false;
    }

    /**
     * Empties the buffer into one insert, casting each record through the configured audit model - old_values and new_values are arrays until it encodes them.
     */
    private static function store(): void
    {
        if (self::$buffered === []) {
            return;
        }

        $buffered = self::$buffered;
        self::$buffered = [];

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $implementation */
        $implementation = Config::get('audit.implementation', DefaultAudit::class);

        $rows = [];
        $columns = [];
        foreach ($buffered as $record) {
            $audit = new $implementation();
            $audit->forceFill($record);
            $row = $audit->getAttributes();
            $columns += $row;
            $rows[] = $row;
        }

        // insert() takes one column list for every row, and a resolver returning nothing leaves its column off the record rather than nulling it.
        $columns = array_fill_keys(array_keys($columns), null);

        new $implementation()->newQuery()->insert(array_map(static fn(array $row): array => [...$columns, ...$row], $rows));
    }
}
