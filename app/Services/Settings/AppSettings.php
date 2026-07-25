<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The admin-editable app-level settings store.
 *
 * The registry at config/settings.php (app) is the closed vocabulary: the app_settings table holds overrides only,
 * reads resolve override-then-default from one cached map, and an unregistered key throws instead of silently inventing state.
 * Writes validate against the registry rules and go through the AppSetting model, whose Auditable trait
 * records them in the audit trail - which is also why no-op writes must never touch a row.
 */
readonly class AppSettings
{
    private const string CACHE_KEY = 'app-settings.overrides';

    /**
     * Whether the key exists in the registry.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('settings.app'));
    }

    /**
     * One resolved setting: the stored override, or the registry default.
     */
    public function get(string $key): mixed
    {
        $entry = $this->registryEntry($key);
        $overrides = $this->overrides();

        return array_key_exists($key, $overrides) ? $overrides[$key] : ($entry['default'] ?? null);
    }

    /**
     * Every registered setting, resolved.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $keys = array_keys((array) config('settings.app'));

        return array_combine($keys, array_map($this->get(...), $keys));
    }

    /**
     * The resolved settings flagged public in the registry - the only ones the unauthenticated bootstrap endpoint may expose.
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $publicKeys = array_keys(array_filter(
            (array) config('settings.app'),
            static fn(array $entry): bool => (bool) ($entry['public'] ?? false),
        ));

        return array_combine($publicKeys, array_map($this->get(...), $publicKeys));
    }

    /**
     * Store a value for the key: validated against the registry rules, persisted as an override, or,
     * when the value equals the registry default, by deleting the override row.
     * No-op writes touch nothing, so the audit trail records only real changes.
     */
    public function set(string $key, mixed $value): void
    {
        $entry = $this->registryEntry($key);

        $rules = ['value' => (array) ($entry['rules'] ?? [])];

        // Array-valued settings validate their shape through the registry's nested rules.
        foreach ((array) ($entry['nested'] ?? []) as $path => $pathRules) {
            $rules["value.{$path}"] = (array) $pathRules;
        }

        Validator::make(['value' => $value], $rules)->validate();

        $override = AppSetting::query()->where('key', $key)->first();

        if ($value === ($entry['default'] ?? null)) {
            $override?->delete();
        } elseif ($override === null) {
            AppSetting::query()->create(['key' => $key, 'value' => $value]);
        } elseif ($override->value !== $value) {
            $override->update(['value' => $value]);
        } else {
            return;
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The stored overrides, as one cached map - a settings read must not cost a query per key.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            static fn(): array => AppSetting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * @return array{type?: string, default?: mixed, rules?: list<string>, nested?: array<string, list<string>>, public?: bool}
     */
    private function registryEntry(string $key): array
    {
        $registry = (array) config('settings.app');

        if (!array_key_exists($key, $registry)) {
            throw new InvalidArgumentException("Unknown app setting [{$key}].");
        }

        return (array) $registry[$key];
    }
}
