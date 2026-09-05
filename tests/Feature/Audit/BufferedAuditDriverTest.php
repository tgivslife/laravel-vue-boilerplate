<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Services\Audit\BufferedAuditDriver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\AuditedItem;
use Tests\TestCase;

/**
 * Buffering trades one insert per audit record for one insert per bulk write, so a run that audits
 * thousands of rows leaves the transaction a single subtransaction rather than thousands.
 * The trail it records has to stay identical to the one the package's own driver writes.
 */
class BufferedAuditDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('audited_items')) {
            Schema::create('audited_items', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('secret')->nullable();
            });
        }

        Relation::morphMap(['audited_item' => AuditedItem::class]);
    }

    /**
     * Counts the statements that write audit records while $work runs.
     */
    private function auditInsertsDuring(callable $work): int
    {
        $inserts = 0;
        DB::listen(static function ($query) use (&$inserts): void {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audits')) {
                $inserts++;
            }
        });

        $work();

        return $inserts;
    }

    public function test_every_buffered_record_is_stored_by_one_insert(): void
    {
        $items = collect(range(1, 5))
            ->map(static fn(int $n): AuditedItem => AuditedItem::query()->create(['name' => "item {$n}"]));
        Audit::query()->delete();

        $result = null;
        $inserts = $this->auditInsertsDuring(function () use ($items, &$result): void {
            $result = BufferedAuditDriver::collect(static function () use ($items): string {
                foreach ($items as $item) {
                    $item->update(['name' => $item->name . ' renamed']);
                }

                return 'done';
            });
        });

        $this->assertSame(1, $inserts, 'The records were stored one insert at a time.');
        $this->assertDatabaseCount('audits', 5);
        $this->assertSame('done', $result, "collect() swallowed its work's return value.");
    }

    public function test_a_run_longer_than_one_batch_stores_every_record(): void
    {
        // Inserted rather than created: a bulk insert fires no model events, so the fixture leaves no audit records of its own behind.
        AuditedItem::query()->insert(collect(range(1, 501))
            ->map(static fn(int $n): array => ['name' => "item {$n}"])->all());
        $items = AuditedItem::query()->get();

        $inserts = $this->auditInsertsDuring(static function () use ($items): void {
            BufferedAuditDriver::collect(static function () use ($items): void {
                foreach ($items as $item) {
                    $item->update(['name' => $item->name . ' renamed']);
                }
            });
        });

        // Two batches, not 501 inserts and not one buffer holding every record.
        $this->assertSame(2, $inserts);
        $this->assertDatabaseCount('audits', 501);
    }

    public function test_a_buffered_record_says_what_the_default_driver_would_have_said(): void
    {
        $direct = AuditedItem::create(['name' => 'alpha']);
        $direct->update(['name' => 'beta']);
        $expected = Audit::query()->latest('id')->first();

        $buffered = AuditedItem::create(['name' => 'alpha']);
        BufferedAuditDriver::collect(static function () use ($buffered): void {
            $buffered->update(['name' => 'beta']);
        });
        $actual = Audit::query()->latest('id')->first();

        $this->assertSame('updated', $actual->event);
        $this->assertSame($expected->auditable_type, $actual->auditable_type);
        $this->assertSame((string)$buffered->getKey(), (string)$actual->auditable_id);
        $this->assertSame(['name' => 'alpha'], $actual->old_values);
        $this->assertSame(['name' => 'beta'], $actual->new_values);
        $this->assertSame($expected->tags, $actual->tags);
        $this->assertSame($expected->url, $actual->url);
        $this->assertSame($expected->ip_address, $actual->ip_address);
        $this->assertSame($expected->user_agent, $actual->user_agent);
        $this->assertNull($actual->user_id);
        $this->assertNotNull($actual->created_at);
    }

    public function test_records_excluded_by_the_package_stay_excluded(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);
        Audit::query()->delete();

        BufferedAuditDriver::collect(static function () use ($item): void {
            // A save changing nothing, and a change to an attribute the model excludes from its trail.
            $item->update(['name' => 'alpha']);
            $item->update(['secret' => 'hidden']);
        });

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_work_that_throws_stores_nothing(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);
        Audit::query()->delete();

        try {
            BufferedAuditDriver::collect(static function () use ($item): void {
                $item->update(['name' => 'beta']);

                throw new RuntimeException('stop');
            });
            $this->fail('The failure did not surface to the caller.');
        } catch (RuntimeException) {
            // Expected: the caller's transaction is the one that decides what survives.
        }

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_the_previous_driver_comes_back_after_a_failure(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);
        $before = config('audit.driver');

        try {
            BufferedAuditDriver::collect(static fn(): never => throw new RuntimeException('stop'));
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame($before, config('audit.driver'));

        Audit::query()->delete();
        $item->update(['name' => 'beta']);
        // Writes after the run audit immediately again.
        $this->assertDatabaseCount('audits', 1);
    }

    public function test_a_nested_run_is_stored_by_the_outer_one(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);
        Audit::query()->delete();

        $inserts = $this->auditInsertsDuring(static function () use ($item): void {
            BufferedAuditDriver::collect(static function () use ($item): void {
                $item->update(['name' => 'beta']);
                BufferedAuditDriver::collect(static function () use ($item): void {
                    $item->update(['name' => 'gamma']);
                });
            });
        });

        $this->assertSame(1, $inserts);
        $this->assertDatabaseCount('audits', 2);
    }
}
