<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_their_name(): void
    {
        $user = $this->createUser();

        $response = $this->actingAsStateful($user)->patchJson('/api/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Popescu',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.first_name', 'Ana')
            ->assertJsonPath('data.last_name', 'Popescu');

        $user->refresh();
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Popescu', $user->last_name);
    }

    public function test_update_requires_both_names(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->patchJson('/api/profile', ['first_name' => '', 'last_name' => 'Popescu'])
            ->assertStatus(422);
    }

    public function test_email_cannot_be_changed_through_the_profile(): void
    {
        $user = $this->createUser();
        $originalEmail = $user->email;

        $this->actingAsStateful($user)->patchJson('/api/profile', [
            'first_name' => 'Ana',
            'last_name' => 'Popescu',
            'email' => 'new@example.com',
        ])->assertStatus(200);

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_api_tokens_cannot_update_the_profile(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)
            ->patchJson('/api/profile', ['first_name' => 'Ana', 'last_name' => 'Popescu'])
            ->assertStatus(403);
    }

    public function test_guests_cannot_update_a_profile(): void
    {
        $this->patchJson('/api/profile', ['first_name' => 'Ana', 'last_name' => 'Popescu'])
            ->assertStatus(401);
    }
}
