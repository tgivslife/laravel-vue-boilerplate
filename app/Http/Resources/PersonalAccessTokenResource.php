<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Metadata for a personal access token. Never exposes the token value:
 * the plaintext exists only in the create response's meta.
 */
final class PersonalAccessTokenResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $this */
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'abilities' => $this->abilities,
            'last_used_at' => $this->last_used_at?->toAtomString(),
            'expires_at' => $this->expires_at?->toAtomString(),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
