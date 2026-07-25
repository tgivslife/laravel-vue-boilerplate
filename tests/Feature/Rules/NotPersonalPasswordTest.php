<?php

namespace Tests\Feature\Rules;

use App\Models\User;
use App\Rules\NotPersonalPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NotPersonalPasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $data
     */
    private function passes(string $password, array $data = []): bool
    {
        return Validator::make(
            [...$data, 'password' => $password],
            ['password' => [new NotPersonalPassword()]],
        )->passes();
    }

    public function test_rejects_the_authenticated_users_identity(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Teodora',
            'last_name' => 'Ionescu',
            'email' => 'teodora.ionescu@example.com',
        ]);

        $this->actingAs($user);

        $this->assertFalse($this->passes('xxTeodora2026!'), 'first name must be rejected');
        $this->assertFalse($this->passes('my-IONESCU-pass'), 'last name must be rejected');
        $this->assertFalse($this->passes('teodora.ionescu#77'), 'email local part must be rejected');
    }

    public function test_rejects_the_reset_requests_email_fragments_without_a_session(): void
    {
        $this->assertFalse(
            $this->passes('around-teodora-9!', ['email' => 'teodora.ionescu@example.com']),
            'local-part fragment from the request data must be rejected',
        );
    }

    public function test_rejects_context_terms(): void
    {
        $this->assertFalse($this->passes('my-parola-here-1'));
    }

    public function test_rejects_the_app_name(): void
    {
        config(['app.name' => 'Contoso']);

        $this->assertFalse($this->passes('my-contoso-entry-1'));
    }

    public function test_ignores_terms_shorter_than_four_characters(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Ana',
            'last_name' => 'Ion',
            'email' => 'a.ion@example.com',
        ]);

        $this->actingAs($user);

        // "dictionary" contains "ion"; three-letter names must not poison unrelated passwords.
        $this->assertTrue($this->passes('dictionary-managed-9'));
    }

    public function test_accepts_an_unrelated_password(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Teodora',
            'last_name' => 'Ionescu',
            'email' => 'teodora.ionescu@example.com',
        ]);

        $this->actingAs($user);

        $this->assertTrue($this->passes('valid horse battery staple'));
    }
}
