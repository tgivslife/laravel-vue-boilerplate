<?php

namespace App\Http\Payloads\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

final readonly class LoginPayload
{
    public function __construct(
        public string $email,
        public string $password,
        public bool $remember,
        public string $ip
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $data = self::validatedData($request);

        return new self(
            email: (string) $data['email'],
            password: (string) $data['password'],
            remember: (bool) ($data['remember'] ?? false),
            ip: (string) $request->ip()
        );
    }

    private static function validatedData(Request $request): array
    {
        if ($request instanceof FormRequest) {
            return $request->validated();
        }

        return $request->all();
    }
}
