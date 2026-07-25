<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuditedItem;
use Tests\TestCase;

class AuditTrailTest extends TestCase
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

    public function test_creating_an_audited_model_records_a_created_entry(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);

        $audit = Audit::query()->sole();

        $this->assertSame('created', $audit->event);
        $this->assertSame('audited_item', $audit->auditable_type);
        $this->assertSame((string) $item->getKey(), (string) $audit->auditable_id);
        $this->assertSame([], $audit->old_values);
        $this->assertEquals(['id' => $item->getKey(), 'name' => 'alpha'], $audit->new_values);
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->impersonator_id);
    }

    public function test_updating_records_only_the_changed_attributes(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);

        $item->update(['name' => 'beta']);

        $audit = Audit::query()->latest('id')->first();

        $this->assertSame('updated', $audit->event);
        $this->assertSame(['name' => 'alpha'], $audit->old_values);
        $this->assertSame(['name' => 'beta'], $audit->new_values);
    }

    public function test_a_no_op_save_records_nothing(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);

        $item->update(['name' => 'alpha']);

        $this->assertDatabaseCount('audits', 1);
    }

    public function test_deleting_records_the_old_values(): void
    {
        $item = AuditedItem::create(['name' => 'alpha']);

        $item->delete();

        $audit = Audit::query()->latest('id')->first();

        $this->assertSame('deleted', $audit->event);
        $this->assertSame('alpha', $audit->old_values['name']);
        $this->assertSame([], $audit->new_values);
    }

    public function test_excluded_attributes_never_reach_the_trail(): void
    {
        $item = AuditedItem::create(['name' => 'alpha', 'secret' => 'hunter2']);
        $item->update(['secret' => 'hunter3']);

        $this->assertDatabaseCount('audits', 1);

        $audit = Audit::query()->sole();

        $this->assertArrayNotHasKey('secret', $audit->new_values);
    }

    public function test_quiet_saves_record_nothing(): void
    {
        $item = new AuditedItem(['name' => 'alpha']);
        $item->saveQuietly();

        $item->name = 'beta';
        $item->saveQuietly();

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_disabling_console_auditing_suppresses_out_of_request_writes(): void
    {
        // The test itself runs in console, so this exercises the production
        // semantics of AUDIT_CONSOLE=false: seeders and commands stop auditing.
        config(['audit.console' => false]);

        AuditedItem::create(['name' => 'alpha']);

        $this->assertDatabaseCount('audits', 0);
    }

    public function test_an_authenticated_write_is_attributed_through_the_user_morph(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        AuditedItem::create(['name' => 'alpha']);

        $audit = Audit::query()->sole();

        $this->assertSame('user', $audit->user_type);
        $this->assertSame($user->getKey(), (int) $audit->user_id);
        $this->assertNull($audit->impersonator_id);
    }

    public function test_a_write_mid_impersonation_names_both_parties(): void
    {
        config(['access.impersonation.enabled' => true]);

        $actor = $this->createUser();
        $actor->givePermissionTo(
            config('permission.models.permission')::findOrCreate('users.impersonate', config('access.guard'))
        );
        $target = $this->createUser();

        $this->actingAsStateful($actor);
        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->app['auth']->forgetGuards();

        AuditedItem::create(['name' => 'alpha']);

        $audit = Audit::query()->sole();

        // The target stays the actor of record; the admin lands beside them.
        $this->assertSame($target->getKey(), (int) $audit->user_id);
        $this->assertSame($actor->getKey(), $audit->impersonator_id);
        $this->assertTrue($audit->impersonator->is($actor));
    }
}
