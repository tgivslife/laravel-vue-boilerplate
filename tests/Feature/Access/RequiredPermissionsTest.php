<?php

namespace Tests\Feature\Access;

use App\Services\Access\AccessScope;
use Tests\Support\RegionDimension;
use Tests\Support\Widget;

class RequiredPermissionsTest extends AccessTestCase
{
    public function test_a_record_without_rules_is_open(): void
    {
        $widget = Widget::create(['name' => 'open']);
        $user = $this->userWithPermissions();

        $this->assertTrue($widget->userCan($user, 'view'));
        $this->assertSame(['open'], $this->visibleWidgetNames($user));
    }

    public function test_class_level_rules_gate_every_record(): void
    {
        Widget::create(['name' => 'first']);
        Widget::create(['name' => 'second']);
        $this->requirePermissions(null, 'view', 'widgets.classified');

        $denied = $this->userWithPermissions();
        $allowed = $this->userWithPermissions('widgets.classified');

        $this->assertSame([], $this->visibleWidgetNames($denied));
        $this->assertSame(['first', 'second'], $this->visibleWidgetNames($allowed));
        $this->assertFalse(Widget::first()->userCan($denied, 'view'));
        $this->assertTrue(Widget::first()->userCan($allowed, 'view'));
    }

    public function test_a_failed_class_gate_short_circuits_without_subqueries(): void
    {
        $this->requirePermissions(null, 'view', 'widgets.classified');
        $denied = $this->userWithPermissions();

        $sql = Widget::query()->visibleTo($denied)->toSql();

        $this->assertStringNotContainsString('exists', strtolower($sql));
    }

    public function test_instance_rules_hide_only_the_protected_record(): void
    {
        Widget::create(['name' => 'open']);
        $locked = Widget::create(['name' => 'locked']);
        $this->requirePermissions($locked, 'view', 'widgets.special');

        $denied = $this->userWithPermissions();
        $allowed = $this->userWithPermissions('widgets.special');

        $this->assertSame(['open'], $this->visibleWidgetNames($denied));
        $this->assertSame(['open', 'locked'], $this->visibleWidgetNames($allowed));
        $this->assertFalse($locked->userCan($denied, 'view'));
        $this->assertTrue($locked->userCan($allowed, 'view'));
    }

    public function test_all_mode_requires_every_permission_in_the_group(): void
    {
        $locked = Widget::create(['name' => 'locked']);
        $this->requirePermissions($locked, 'view', ['widgets.a', 'widgets.b'], 'all');

        $partial = $this->userWithPermissions('widgets.a');
        $full = $this->userWithPermissions('widgets.a', 'widgets.b');

        $this->assertFalse($locked->userCan($partial, 'view'));
        $this->assertSame([], $this->visibleWidgetNames($partial));
        $this->assertTrue($locked->userCan($full, 'view'));
        $this->assertSame(['locked'], $this->visibleWidgetNames($full));
    }

    public function test_any_mode_requires_at_least_one_permission_in_the_group(): void
    {
        $locked = Widget::create(['name' => 'locked']);
        $this->requirePermissions($locked, 'view', ['widgets.a', 'widgets.b'], 'any');

        $none = $this->userWithPermissions();
        $one = $this->userWithPermissions('widgets.b');

        $this->assertFalse($locked->userCan($none, 'view'));
        $this->assertSame([], $this->visibleWidgetNames($none));
        $this->assertTrue($locked->userCan($one, 'view'));
        $this->assertSame(['locked'], $this->visibleWidgetNames($one));
    }

    public function test_class_and_instance_groups_are_both_required(): void
    {
        $locked = Widget::create(['name' => 'locked']);
        $this->requirePermissions(null, 'view', 'widgets.base');
        $this->requirePermissions($locked, 'view', 'widgets.special');

        $classOnly = $this->userWithPermissions('widgets.base');
        $instanceOnly = $this->userWithPermissions('widgets.special');
        $both = $this->userWithPermissions('widgets.base', 'widgets.special');

        $this->assertFalse($locked->userCan($classOnly, 'view'));
        $this->assertFalse($locked->userCan($instanceOnly, 'view'));
        $this->assertTrue($locked->userCan($both, 'view'));

        $this->assertSame([], $this->visibleWidgetNames($instanceOnly));
        $this->assertSame(['locked'], $this->visibleWidgetNames($both));
    }

    public function test_rule_types_are_independent(): void
    {
        $widget = Widget::create(['name' => 'editable']);
        $this->requirePermissions($widget, 'update', 'widgets.editor');

        $viewer = $this->userWithPermissions();

        $this->assertTrue($widget->userCan($viewer, 'view'));
        $this->assertFalse($widget->userCan($viewer, 'update'));
        $this->assertSame(['editable'], $this->visibleWidgetNames($viewer, 'view'));
        $this->assertSame([], $this->visibleWidgetNames($viewer, 'update'));
    }

    public function test_super_admins_bypass_rules_and_dimensions(): void
    {
        config(['access.dimensions' => [RegionDimension::class]]);
        RegionDimension::$userRegions = [];

        $locked = Widget::create(['name' => 'locked', 'region' => 'north']);
        $this->requirePermissions($locked, 'view', 'widgets.special');

        $superAdmin = $this->createUser();
        $superAdmin->assignRole(
            config('permission.models.role')::findOrCreate(config('access.super_admin_role'), config('access.guard'))
        );

        $this->assertTrue($locked->userCan($superAdmin, 'view'));
        $this->assertSame(['locked'], $this->visibleWidgetNames($superAdmin));
    }

    public function test_dimensions_constrain_queries_and_veto_records(): void
    {
        config(['access.dimensions' => [RegionDimension::class]]);

        $north = Widget::create(['name' => 'north', 'region' => 'north']);
        $south = Widget::create(['name' => 'south', 'region' => 'south']);

        $user = $this->userWithPermissions();
        RegionDimension::$userRegions = [$user->getKey() => 'north'];

        $this->assertSame(['north'], $this->visibleWidgetNames($user));
        $this->assertTrue($north->userCan($user, 'view'));
        $this->assertFalse($south->userCan($user, 'view'));
    }

    public function test_visibility_without_instance_rules_adds_no_subquery(): void
    {
        Widget::create(['name' => 'open']);
        $user = $this->userWithPermissions();

        $sql = Widget::query()->visibleTo($user)->toSql();

        $this->assertStringNotContainsString('exists', strtolower($sql));
    }

    public function test_revocation_applies_on_the_next_request_scope(): void
    {
        $locked = Widget::create(['name' => 'locked']);
        $this->requirePermissions($locked, 'view', 'widgets.special');

        $user = $this->userWithPermissions('widgets.special');
        $this->assertTrue($locked->userCan($user, 'view'));

        $user->revokePermissionTo('widgets.special');
        app(AccessScope::class)->flush(); // what a fresh request starts with

        $this->assertFalse($locked->fresh()->userCan($user->fresh(), 'view'));
    }

    protected function tearDown(): void
    {
        RegionDimension::$userRegions = [];

        parent::tearDown();
    }
}
