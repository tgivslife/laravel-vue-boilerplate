<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AttachRequestId: client-supplied correlation IDs are honored only when the request
 * comes from a trusted proxy (request.id.trust_proxy_only, on by default), so a direct
 * client cannot pin one ID across requests and poison log correlation - while the
 * ingress-issued IDs the setting exists for still flow through.
 */
class AttachRequestIdTest extends TestCase
{
    public function test_a_direct_clients_id_is_replaced_by_default(): void
    {
        // The local .env trusts REMOTE_ADDR, which would make every test request "from a proxy";
        // trust nobody to model the shipped default (TRUSTED_PROXIES empty) and a direct client.
        TrustProxies::at([]);

        $response = $this->getJson('/api/auth/methods', ['X-Request-Id' => 'attacker-pinned-id']);

        $requestId = (string) $response->headers->get('X-Request-Id');

        $this->assertNotSame('attacker-pinned-id', $requestId);
        $this->assertTrue(Str::isUuid($requestId));
    }

    public function test_a_trusted_proxys_id_is_honored(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $this->getJson('/api/auth/methods', ['X-Request-Id' => 'ingress-issued-id'])
            ->assertHeader('X-Request-Id', 'ingress-issued-id');
    }

    public function test_an_invalid_id_is_replaced_even_from_a_trusted_proxy(): void
    {
        TrustProxies::at(['127.0.0.1']);

        // Fails the character whitelist; the length bounds and pattern still apply to trusted senders.
        $response = $this->getJson('/api/auth/methods', ['X-Request-Id' => 'bad id with spaces']);

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));
    }
}
