<?php

namespace Database\Factories;

use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MagicLinkToken>
 */
class MagicLinkTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', fake()->unique()->sha1()),
            'expires_at' => now()->addMinutes(15),
            'consumed_at' => null,
        ];
    }

    /**
     * Indicate that the token is an admin invitation rather than a self-serve login link.
     */
    public function invitation(): static
    {
        return $this->state(fn(array $attributes) => [
            'purpose' => MagicLinkToken::PURPOSE_INVITATION,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Indicate that the token's lifetime has already elapsed.
     */
    public function expired(): static
    {
        return $this->state(fn(array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the token has already been used.
     */
    public function consumed(): static
    {
        return $this->state(fn(array $attributes) => [
            'consumed_at' => now()->subMinute(),
        ]);
    }
}
