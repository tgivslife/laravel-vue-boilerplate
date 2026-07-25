<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_resolve_to_registry_defaults_until_chosen(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.preferences.theme', 'auto')
            ->assertJsonPath('data.preferences.locale', null);
    }

    public function test_user_can_update_their_preferences(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->patchJson('/api/preferences', ['locale' => 'ro', 'theme' => 'dark'])
            ->assertStatus(200)
            ->assertJsonPath('data.preferences.locale', 'ro')
            ->assertJsonPath('data.preferences.theme', 'dark');

        $user->refresh();
        $this->assertSame('ro', $user->preference('locale'));
        $this->assertSame('dark', $user->preference('theme'));
    }

    public function test_partial_updates_keep_the_other_preferences(): void
    {
        $user = $this->createUser();
        $user->forceFill(['preferences' => ['locale' => 'ro']])->save();

        $this->actingAsStateful($user)
            ->patchJson('/api/preferences', ['theme' => 'dark'])
            ->assertStatus(200)
            ->assertJsonPath('data.preferences.locale', 'ro')
            ->assertJsonPath('data.preferences.theme', 'dark');
    }

    public function test_values_outside_the_registry_rules_are_rejected(): void
    {
        $user = $this->createUser();
        $this->actingAsStateful($user);

        $this->patchJson('/api/preferences', ['locale' => 'xx'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'locale');

        $this->patchJson('/api/preferences', ['theme' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'theme');
    }

    public function test_unregistered_keys_are_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->patchJson('/api/preferences', ['favorite_color' => 'red'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.name', 'favorite_color');
    }

    public function test_guests_are_unauthorized(): void
    {
        $this->patchJson('/api/preferences', ['theme' => 'dark'])->assertStatus(401);
    }

    public function test_api_tokens_cannot_update_preferences(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)
            ->patchJson('/api/preferences', ['theme' => 'dark'])
            ->assertStatus(403);
    }

    public function test_impersonated_sessions_cannot_update_preferences(): void
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

        $this->patchJson('/api/preferences', ['theme' => 'dark'])->assertStatus(403);
    }

    public function test_the_stored_locale_becomes_the_preferred_notification_locale(): void
    {
        $user = $this->createUser();

        $this->assertSame(config('app.locale'), $user->preferredLocale());

        $user->forceFill(['preferences' => ['locale' => 'ro']])->save();

        $this->assertSame('ro', $user->preferredLocale());
    }
}
