<?php

namespace App\Http\Resources;

use App\Support\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One live session row from the SessionRegistry, shared by the settings sessions list and the admin user detail page
 * so the payload shape is defined exactly once.
 *
 * Raw session ids never leave the server: rows are addressed by a SHA-256 digest, so a listed session can be revoked but never hijacked.
 * `is_current` marks the requester's own session.
 */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sessionId = (string) $this->resource->session_id;

        return [
            'id' => hash('sha256', $sessionId),
            'device_name' => Device::nameFromUserAgent((string) $this->resource->user_agent),
            'user_agent' => (string) $this->resource->user_agent,
            'ip_address' => $this->resource->ip_address,
            'last_activity_at' => Carbon::createFromTimestamp((int) $this->resource->last_activity)->toISOString(),
            'is_current' => $request->hasSession() && hash_equals($request->session()->getId(), $sessionId),
        ];
    }
}
