<?php

namespace App\Http\Resources;

use App\Services\Auth\SessionRegistry;
use App\Support\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One live session row from the SessionRegistry, shared by the settings sessions list and the admin user detail page
 * so the payload shape is defined exactly once.
 *
 * Raw session ids never leave the server: rows are addressed by a SHA-256 digest, so a listed session can be revoked but never hijacked.
 * `is_current` marks the requester's own session, by registry row rather than session id, since the id may have
 * rotated; `remembered` a browser holding a remember-me cookie, whose revocation ends remember-me on every browser.
 */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => hash('sha256', (string) $this->resource->session_id),
            'device_name' => Device::nameFromUserAgent((string) $this->resource->user_agent),
            'user_agent' => (string) $this->resource->user_agent,
            'ip_address' => $this->resource->ip_address,
            'last_activity_at' => Carbon::createFromTimestamp((int) $this->resource->last_activity)->toISOString(),
            'is_current' => (int) $this->resource->id === app(SessionRegistry::class)->currentRowId($request),
            'remembered' => (bool) $this->resource->remembered,
        ];
    }
}
