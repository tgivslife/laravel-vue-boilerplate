<?php

namespace Tests\Feature\Auth;

use App\Notifications\InvitationNotification;
use App\Notifications\MagicLinkNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The token-bearing auth mails carry a live credential in their URL. Queued, that payload would otherwise sit
 * readable in the queue backend, failed_jobs and Horizon's job detail for the retention window - a side channel
 * around the hashed-at-rest token store. ShouldBeEncrypted keeps the serialized payload ciphertext at rest.
 */
class QueuedCredentialMailEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_bearing_notifications_are_marked_for_encryption(): void
    {
        // The three auth mails whose URL carries a live token. Scalar-only notifications
        // (device snapshots, lockout notices) deliberately stay off this list.
        $tokenBearing = [
            MagicLinkNotification::class,
            InvitationNotification::class,
            ResetPasswordNotification::class,
        ];

        foreach ($tokenBearing as $notification) {
            $this->assertTrue(
                is_subclass_of($notification, ShouldBeEncrypted::class),
                "{$notification} must implement ShouldBeEncrypted so its queued payload never stores the token in cleartext."
            );
        }
    }

    public function test_the_serialized_queue_payload_does_not_carry_the_token_in_cleartext(): void
    {
        // A real async driver so the notification is actually serialized onto a backend (sync would not).
        config(['queue.default' => 'database']);

        $token = 'sentinel-token-'.bin2hex(random_bytes(8));

        Notification::route('mail', 'recipient@example.test')->notify(
            new MagicLinkNotification(
                url: 'https://app.test/auth/magic-link?token='.$token,
                expiresInMinutes: 15,
                deviceName: 'Test Device',
                ipAddress: '203.0.113.5',
                requestedAt: now(),
            )
        );

        $payload = DB::table('jobs')->value('payload');

        $this->assertNotNull($payload, 'The notification should have been queued onto the database driver.');
        $this->assertStringNotContainsString(
            $token,
            $payload,
            'The plaintext token leaked into the queue payload - the notification is not being encrypted.'
        );
    }
}
