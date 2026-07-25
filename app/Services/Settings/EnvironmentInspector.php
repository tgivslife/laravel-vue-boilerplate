<?php

namespace App\Services\Settings;

/**
 * Builds the read-only environment report for the admin settings page: the allowlisted variables from config/settings.php (environment),
 * read from the process environment so the report shows what the running container actually carries - even with cached config,
 * where the .env file is not loaded but container-level variables remain.
 *
 * Secret-flagged variables report only whether they are set; their values never leave the server.
 */
readonly class EnvironmentInspector
{
    /**
     * @return list<array{key: string, variables: list<array{name: string, value: mixed, set: bool, secret: bool}>}>
     */
    public function report(): array
    {
        $categories = (array) config('settings.environment.categories', []);

        return array_map(static fn(string $key): array => [
            'key' => $key,
            'variables' => array_map(
                self::variable(...),
                array_values((array) $categories[$key]),
            ),
        ], array_keys($categories));
    }

    /**
     * @return array{name: string, value: mixed, set: bool, secret: bool}
     */
    private static function variable(string $name): array
    {
        $value = env($name);
        $secret = self::isSecret($name);

        return [
            'name' => $name,
            'value' => $secret ? null : $value,
            'set' => $value !== null,
            'secret' => $secret,
        ];
    }

    private static function isSecret(string $name): bool
    {
        foreach ((array) config('settings.environment.secret_suffixes', []) as $suffix) {
            if (str_ends_with($name, (string) $suffix)) {
                return true;
            }
        }

        return in_array($name, (array) config('settings.environment.secrets', []), true);
    }
}
