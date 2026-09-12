<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeSessionRegistryCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A tombstone ages from its revocation, not from the row's last activity: the copy of a revoked session written
     * back late lives a session lifetime from the revocation, so a tombstone on a long-idle row must outlive that.
     */
    public function test_tombstones_age_from_their_revocation_not_their_last_activity(): void
    {
        $user = $this->createUser();
        $freshTombstone = $this->row($user, activityMinutesAgo: 600, revokedMinutesAgo: 7);
        $oldTombstone = $this->row($user, activityMinutesAgo: 600, revokedMinutesAgo: 600);
        $idleLive = $this->row($user, activityMinutesAgo: 600);
        $activeLive = $this->row($user, activityMinutesAgo: 1);

        $this->artisan('auth:purge-session-registry')
            ->expectsOutputToContain('Purged 2 session registry entries.')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$activeLive, $freshTombstone],
            DB::table('user_sessions')->pluck('session_id')->all(),
        );
        $this->assertDatabaseMissing('user_sessions', ['session_id' => $oldTombstone]);
        $this->assertDatabaseMissing('user_sessions', ['session_id' => $idleLive]);
    }

    private function row(User $user, int $activityMinutesAgo, ?int $revokedMinutesAgo = null): string
    {
        $sessionId = fake()->regexify('[A-Za-z0-9]{40}');

        DB::table('user_sessions')->insert([
            'user_id' => $user->getKey(),
            'session_id' => $sessionId,
            'last_activity' => now()->subMinutes($activityMinutesAgo)->getTimestamp(),
            'revoked_at' => $revokedMinutesAgo === null ? null : now()->subMinutes($revokedMinutesAgo),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $sessionId;
    }
}
