<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuditPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    private function auditEntryAt(Carbon $createdAt): Audit
    {
        $audit = Audit::query()->create([
            'event' => 'created',
            'auditable_type' => 'audited_item',
            'auditable_id' => 1,
            'old_values' => [],
            'new_values' => ['name' => 'alpha'],
        ]);

        $audit->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $audit;
    }

    public function test_entries_past_the_retention_period_are_purged(): void
    {
        config(['audit.retention_days' => 30]);

        $stale = $this->auditEntryAt(now()->subDays(31));
        $fresh = $this->auditEntryAt(now()->subDays(29));

        $this->artisan('audit:purge-logs')
            ->expectsOutputToContain('Purged 1 audit entries.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('audits', ['id' => $stale->getKey()]);
        $this->assertDatabaseHas('audits', ['id' => $fresh->getKey()]);
    }

    public function test_non_positive_retention_disables_pruning(): void
    {
        config(['audit.retention_days' => 0]);

        $this->auditEntryAt(now()->subYears(10));

        $this->artisan('audit:purge-logs')
            ->expectsOutputToContain('Audit retention is unlimited; nothing purged.')
            ->assertSuccessful();

        $this->assertDatabaseCount('audits', 1);
    }
}
