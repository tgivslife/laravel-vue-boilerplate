<?php

namespace Tests\Feature\Access;

use App\Models\AppSetting;
use App\Models\Audit;
use App\Services\Settings\AppSettings;

class AppSettingsTest extends AccessTestCase
{
    /**
     * Register a throwaway scalar setting: the generic store behaviors (override round-trip,
     * audit, no-op, reset, cache) are exercised against this instead of a shipped registry
     * entry, so trimming the real registry never silently drops their coverage.
     */
    private function registerContactEmailSetting(): void
    {
        config(['settings.app' => [
            ...config('settings.app'),
            'contact_email' => [
                'type' => 'email',
                'default' => null,
                'rules' => ['nullable', 'email'],
                'public' => false,
            ],
        ]]);
    }

    public function test_the_settings_surface_requires_the_manage_capability(): void
    {
        $this->actingAsStateful($this->createUser());

        $this->getJson('/api/access/settings')->assertForbidden();
        $this->putJson('/api/access/settings/announcement', [
            'value' => ['enabled' => false, 'level' => 'info', 'message' => []],
        ])->assertForbidden();
    }

    public function test_the_editor_lists_the_registry_with_resolved_values(): void
    {
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->getJson('/api/access/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.0.key', 'announcement')
            ->assertJsonPath('data.settings.0.type', 'announcement')
            ->assertJsonPath('data.settings.0.default.enabled', false)
            ->assertJsonPath('data.settings.0.nested.level.2', 'in:info,warning,error')
            ->assertJsonPath('data.settings.0.public', true);
    }

    public function test_the_announcement_accepts_a_localized_payload(): void
    {
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $payload = [
            'enabled' => true,
            'level' => 'warning',
            'message' => ['en' => 'Maintenance tonight at 22:00.', 'ro' => 'Mentenanță diseară la 22:00.'],
        ];

        $this->putJson('/api/access/settings/announcement', ['value' => $payload])
            ->assertOk()
            ->assertJsonPath('data.value.level', 'warning')
            ->assertJsonPath('data.value.message.ro', 'Mentenanță diseară la 22:00.');

        $this->assertSame($payload, app(AppSettings::class)->get('announcement'));

        // Submitting the registry default resets: the override row disappears.
        $this->putJson('/api/access/settings/announcement', ['value' => [
            'enabled' => false, 'level' => 'info', 'message' => [],
        ]])->assertOk();

        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_the_announcement_shape_is_validated_through_the_nested_rules(): void
    {
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        // An unknown severity level.
        $this->putJson('/api/access/settings/announcement', ['value' => [
            'enabled' => true, 'level' => 'urgent', 'message' => [],
        ]])->assertStatus(422)->assertJsonPath('errors.0.name', 'value.level');

        // A message keyed by an unsupported locale.
        $this->putJson('/api/access/settings/announcement', ['value' => [
            'enabled' => true, 'level' => 'info', 'message' => ['fr' => 'Bonjour'],
        ]])->assertStatus(422)->assertJsonPath('errors.0.name', 'value.message');

        // A key outside the announcement shape.
        $this->putJson('/api/access/settings/announcement', ['value' => [
            'enabled' => true, 'level' => 'info', 'message' => [], 'dismissible' => true,
        ]])->assertStatus(422)->assertJsonPath('errors.0.name', 'value');
    }

    public function test_updating_a_setting_stores_the_override_and_audits_it(): void
    {
        $this->registerContactEmailSetting();
        $admin = $this->userWithPermissions('settings.manage');
        $this->actingAsStateful($admin);

        $this->putJson('/api/access/settings/contact_email', ['value' => 'help@example.com'])
            ->assertOk()
            ->assertJsonPath('data.value', 'help@example.com');

        $this->assertDatabaseHas('app_settings', ['key' => 'contact_email']);

        $audit = Audit::query()->sole();
        $this->assertSame('created', $audit->event);
        $this->assertSame('app_setting', $audit->auditable_type);
        $this->assertSame('user', $audit->user_type);
        $this->assertSame($admin->getKey(), (int) $audit->user_id);
        // Audits store attributes as persisted: the json cast keeps scalars JSON-encoded.
        $this->assertSame(json_encode('help@example.com'), $audit->new_values['value']);
    }

    public function test_changing_a_setting_audits_old_and_new_values(): void
    {
        $this->registerContactEmailSetting();
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->putJson('/api/access/settings/contact_email', ['value' => 'old@example.com'])->assertOk();
        $this->putJson('/api/access/settings/contact_email', ['value' => 'new@example.com'])->assertOk();

        $audit = Audit::query()->latest('id')->first();
        $this->assertSame('updated', $audit->event);
        $this->assertSame(json_encode('old@example.com'), $audit->old_values['value']);
        $this->assertSame(json_encode('new@example.com'), $audit->new_values['value']);
    }

    public function test_a_no_op_write_neither_touches_the_row_nor_audits(): void
    {
        $this->registerContactEmailSetting();
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->putJson('/api/access/settings/contact_email', ['value' => 'help@example.com'])->assertOk();
        $this->putJson('/api/access/settings/contact_email', ['value' => 'help@example.com'])->assertOk();

        $this->assertSame(1, Audit::query()->count());
    }

    public function test_resetting_to_the_default_deletes_the_override(): void
    {
        $this->registerContactEmailSetting();
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->putJson('/api/access/settings/contact_email', ['value' => 'help@example.com'])->assertOk();
        $this->putJson('/api/access/settings/contact_email', ['value' => null])->assertOk();

        $this->assertDatabaseCount('app_settings', 0);
        $this->assertSame('deleted', Audit::query()->latest('id')->first()->event);
        $this->assertNull(app(AppSettings::class)->get('contact_email'));
    }

    public function test_values_outside_the_registry_rules_are_rejected(): void
    {
        $this->registerContactEmailSetting();
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->putJson('/api/access/settings/contact_email', ['value' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'value');
    }

    public function test_unregistered_keys_are_not_found(): void
    {
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->putJson('/api/access/settings/made_up', ['value' => 'x'])->assertNotFound();
    }

    public function test_updates_are_visible_through_the_cached_reader(): void
    {
        $this->registerContactEmailSetting();
        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $settings = app(AppSettings::class);
        $this->assertNull($settings->get('contact_email'));

        $this->putJson('/api/access/settings/contact_email', ['value' => 'help@example.com'])->assertOk();

        $this->assertSame('help@example.com', $settings->get('contact_email'));
    }

    public function test_the_environment_report_requires_the_manage_capability(): void
    {
        $this->actingAsStateful($this->createUser());

        $this->getJson('/api/access/settings/environment')->assertForbidden();
        $this->getJson('/api/access/settings/config')->assertForbidden();
    }

    public function test_the_config_report_lists_effective_values_and_masks_secrets(): void
    {
        config([
            'demo.api_token' => 'super-secret',
            'settings.config' => [
                'secret_suffixes' => ['token'],
                'secrets' => [],
                'categories' => ['demo' => ['app.env', 'app.supported_locales', 'demo.api_token', 'demo.missing']],
            ],
        ]);

        $this->actingAsStateful($this->userWithPermissions('settings.manage'));

        $this->getJson('/api/access/settings/config')
            ->assertOk()
            ->assertJsonPath('data.categories.0.key', 'demo')
            ->assertJsonPath('data.categories.0.variables.0.name', 'app.env')
            ->assertJsonPath('data.categories.0.variables.0.value', 'testing')
            // Array-valued config paths come through as-is.
            ->assertJsonPath('data.categories.0.variables.1.value', config('app.supported_locales'))
            // The secret's value never leaves the server - only that it is set.
            ->assertJsonPath('data.categories.0.variables.2.value', null)
            ->assertJsonPath('data.categories.0.variables.2.set', true)
            ->assertJsonPath('data.categories.0.variables.2.secret', true)
            ->assertJsonPath('data.categories.0.variables.3.set', false);
    }

    public function test_the_environment_report_lists_allowlisted_variables_and_masks_secrets(): void
    {
        $_ENV['DEMO_SERVICE_TOKEN'] = 'super-secret';

        try {
            config(['settings.environment' => [
                'secret_suffixes' => ['_TOKEN'],
                'secrets' => [],
                'categories' => ['demo' => ['APP_ENV', 'DEMO_SERVICE_TOKEN', 'DEMO_MISSING_VAR']],
            ]]);

            $this->actingAsStateful($this->userWithPermissions('settings.manage'));

            $this->getJson('/api/access/settings/environment')
                ->assertOk()
                ->assertJsonPath('data.categories.0.key', 'demo')
                ->assertJsonPath('data.categories.0.variables.0.name', 'APP_ENV')
                ->assertJsonPath('data.categories.0.variables.0.value', 'testing')
                ->assertJsonPath('data.categories.0.variables.0.secret', false)
                // The secret's value never leaves the server - only that it is set.
                ->assertJsonPath('data.categories.0.variables.1.value', null)
                ->assertJsonPath('data.categories.0.variables.1.set', true)
                ->assertJsonPath('data.categories.0.variables.1.secret', true)
                ->assertJsonPath('data.categories.0.variables.2.set', false);
        } finally {
            unset($_ENV['DEMO_SERVICE_TOKEN']);
        }
    }

    public function test_the_public_endpoint_exposes_only_public_settings_to_guests(): void
    {
        config([
            'settings.app' => [
                'support_email' => ['default' => null, 'rules' => ['nullable', 'email'], 'public' => true],
                'internal_flag' => ['default' => 'on', 'rules' => ['required', 'string'], 'public' => false],
            ],
        ]);

        AppSetting::query()->create(['key' => 'support_email', 'value' => 'help@example.com']);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.support_email', 'help@example.com')
            ->assertJsonMissingPath('data.settings.internal_flag');
    }
}
