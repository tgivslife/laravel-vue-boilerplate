<?php

namespace App\Services\Settings;

/**
 * Builds the read-only config report for the admin settings page: the allowlisted dot-notation paths from
 * config/settings.php (config), read through the configuration repository so the report shows the effective values
 * the application runs with - environment merged over defaults, including cached config - where the environment report
 * only shows what the container carries.
 *
 * Secret-flagged paths report only whether they are set; their values never leave the server.
 */
readonly class ConfigInspector
{
    /**
     * @return list<array{key: string, variables: list<array{name: string, value: mixed, set: bool, secret: bool}>}>
     */
    public function report(): array
    {
        $categories = (array) config('settings.config.categories', []);

        return array_map(static fn(string $key): array => [
            'key' => $key,
            'variables' => array_map(
                self::value(...),
                array_values((array) $categories[$key]),
            ),
        ], array_keys($categories));
    }

    /**
     * @return array{name: string, value: mixed, set: bool, secret: bool}
     */
    private static function value(string $path): array
    {
        $secret = self::isSecret($path);
        $set = config()->has($path);

        return [
            'name' => $path,
            'value' => $secret || !$set ? null : config($path),
            'set' => $set,
            'secret' => $secret,
        ];
    }

    /**
     * Suffixes match the end of the final path segment, so both naming styles are caught:
     * app.key, security.captcha.secret, services.acme.api_token.
     */
    private static function isSecret(string $path): bool
    {
        $leaf = str_contains($path, '.') ? substr($path, strrpos($path, '.') + 1) : $path;

        foreach ((array) config('settings.config.secret_suffixes', []) as $suffix) {
            if (str_ends_with($leaf, (string) $suffix)) {
                return true;
            }
        }

        return in_array($path, (array) config('settings.config.secrets', []), true);
    }
}
