<?php

namespace App\Http\Resources;

use App\Models\AuthenticationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One authentication-log entry, shared by the settings history and the admin user detail page
 * so the payload shape is defined exactly once.
 */
final class AuthenticationLogEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AuthenticationLog $log */
        $log = $this->resource;

        return [
            'device_name' => $log->device_name,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'login_at' => $log->login_at?->toISOString(),
            'login_successful' => $log->login_successful,
            'login_method' => $log->login_method,
            'last_activity_at' => $log->last_activity_at?->toISOString(),
        ];
    }
}
