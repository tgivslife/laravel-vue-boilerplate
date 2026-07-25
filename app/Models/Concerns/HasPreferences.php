<?php

namespace App\Models\Concerns;

use InvalidArgumentException;

/**
 * Server-persisted user preferences (users.preferences JSON column).
 *
 * The registry at config/settings.php (preferences) is the closed vocabulary: the column stores only overrides the
 * user actually chose, reads merge them over the registry defaults, and an unregistered key throws instead of silently inventing state.
 */
trait HasPreferences
{
    /**
     * One resolved preference: the stored override, or the registry default.
     */
    public function preference(string $key): mixed
    {
        $registry = (array) config('settings.preferences');

        if (!array_key_exists($key, $registry)) {
            throw new InvalidArgumentException("Unknown user preference [{$key}].");
        }

        return ((array) $this->preferences)[$key] ?? $registry[$key]['default'] ?? null;
    }

    /**
     * Every registered preference, resolved (overrides merged over defaults) - the shape the SPA hydrates from.
     *
     * @return array<string, mixed>
     */
    public function resolvedPreferences(): array
    {
        $keys = array_keys((array) config('settings.preferences'));

        return array_combine($keys, array_map($this->preference(...), $keys));
    }

    /**
     * The user's preferred locale (framework customization hook, HasLocalePreference):
     * localizes queued notifications and mail without callers passing a locale around.
     * Falls back to the app locale while the user has not chosen one.
     */
    public function preferredLocale(): string
    {
        return (string) ($this->preference('locale') ?? config('app.locale'));
    }
}
