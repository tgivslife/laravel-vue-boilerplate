<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAuthenticationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_are_listed_newest_first(): void
    {
        $user = $this->createUser();
        $this->createLogEntry($user, loginAt: now()->subDays(2), successful: true);
        $this->createLogEntry($user, loginAt: now()->subDay(), successful: false);

        $response = $this->actingAsStateful($user)->getJson('/api/authentication-log');

        $response->assertStatus(200);

        // The stateful login itself writes the newest entry.
        $entries = $response->json('data.entries');
        $this->assertCount(3, $entries);
        $this->assertTrue($entries[0]['login_successful']);
        $this->assertFalse($entries[1]['login_successful']);
        $this->assertSame('198.51.100.7', $entries[1]['ip_address']);
        $this->assertStringContainsString('Windows', $entries[1]['device_name']);
        $this->assertStringContainsString('Mozilla/5.0', $entries[1]['user_agent']);
        // The stateful test login is a password login; seeded rows have no method.
        $this->assertSame('password', $entries[0]['login_method']);
        $this->assertNull($entries[1]['login_method']);
    }

    public function test_the_log_is_paginated(): void
    {
        config(['security.authentication_log.page_size' => 2]);
        $user = $this->createUser();
        $this->createLogEntry($user, loginAt: now()->subDays(3), successful: true);
        $this->createLogEntry($user, loginAt: now()->subDays(2), successful: true);

        $this->actingAsStateful($user);

        $firstPage = $this->getJson('/api/authentication-log');
        $firstPage->assertStatus(200)
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.has_more', true);

        $secondPage = $this->getJson('/api/authentication-log?page=2');
        $secondPage->assertStatus(200)
            ->assertJsonCount(1, 'data.entries')
            ->assertJsonPath('data.has_more', false);
    }

    public function test_the_log_can_be_filtered_by_day(): void
    {
        $user = $this->createUser();
        $this->createLogEntry($user, loginAt: now()->subDays(2), successful: true, ip: '198.51.100.1');
        $this->createLogEntry($user, loginAt: now()->subDay(), successful: false, ip: '198.51.100.2');

        $this->actingAsStateful($user);

        $response = $this->getJson('/api/authentication-log?date='.now()->subDay()->format('Y-m-d'));

        $response->assertStatus(200)->assertJsonCount(1, 'data.entries');
        $this->assertSame('198.51.100.2', $response->json('data.entries.0.ip_address'));
    }

    public function test_the_day_filter_must_be_a_valid_date(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->getJson('/api/authentication-log?date=not-a-date')
            ->assertStatus(422);
    }

    public function test_only_the_own_log_is_visible(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $this->createLogEntry($other, loginAt: now()->subDay(), successful: true, ip: '203.0.113.99');

        $response = $this->actingAsStateful($user)->getJson('/api/authentication-log');

        $ips = collect($response->json('data.entries'))->pluck('ip_address');
        $this->assertFalse($ips->contains('203.0.113.99'));
    }

    public function test_api_tokens_cannot_read_the_log(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->getJson('/api/authentication-log')->assertStatus(403);
    }

    public function test_guests_cannot_read_the_log(): void
    {
        $this->getJson('/api/authentication-log')->assertStatus(401);
    }

    /**
     * Insert an authentication log row directly, as the login listeners
     * would.
     */
    private function createLogEntry(User $user, mixed $loginAt, bool $successful, string $ip = '198.51.100.7'): void
    {
        $user->authentications()->create([
            'ip_address' => $ip,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'device_id' => hash('sha256', 'test-device'),
            'device_name' => 'Windows 10 / Chrome 120.0',
            'login_at' => $loginAt,
            'login_successful' => $successful,
        ]);
    }
}
