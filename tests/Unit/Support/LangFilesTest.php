<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * The guards over the lang files: every validated field renders with a reader-friendly name, and
 * the two locales declare the same keys - a key missing from one falls back to English, or renders
 * as the raw dotted key, silently rather than failing anywhere visible.
 */
class LangFilesTest extends TestCase
{
    /**
     * The app is never booted here, so the paths are resolved off this file rather than base_path().
     */
    private function basePath(string $path): string
    {
        return dirname(__DIR__, 3) . '/' . $path;
    }

    /**
     * Every field a form request validates, read off the `'field' => [...]` rule keys rather than by
     * calling rules() - a request's rules may reach for the route or the signed-in user.
     *
     * @return list<string>
     */
    private function validatedFields(): array
    {
        $fields = [];
        foreach (glob($this->basePath('app/Http/Requests/{*.php,*/*.php}'), GLOB_BRACE) as $file) {
            foreach (file($file) as $line) {
                if (preg_match("/^\s*'([a-zA-Z0-9_.*]+)'\s*=>\s*\[/", $line, $matches)) {
                    $fields[] = $matches[1];
                }
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $locale): array
    {
        $lines = require $this->basePath("lang/{$locale}/validation.php");

        return $lines['attributes'];
    }

    /**
     * Every leaf key of a nested lang array, dotted the way __() addresses it.
     *
     * @param array<string, mixed> $lines
     * @return list<string>
     */
    private function dottedKeys(array $lines, string $prefix = ''): array
    {
        $keys = [];
        foreach ($lines as $key => $value) {
            $dotted = $prefix === '' ? (string)$key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = [...$keys, ...$this->dottedKeys($value, $dotted)];
            } else {
                $keys[] = $dotted;
            }
        }

        return $keys;
    }

    public function test_every_validated_field_has_a_reader_friendly_attribute_name(): void
    {
        $missing = array_values(array_diff($this->validatedFields(), array_keys($this->attributes('en'))));

        $this->assertSame([], $missing,
            'Fields without a validation.attributes entry render raw in messages: ' . implode(', ', $missing));
    }

    /**
     * The whole file, leaf by leaf - not just the top-level groups: after() hooks add hand-written
     * message keys (coordinates_pair, the map box set), rules nest per-type variants
     * (between.numeric, password.mixed), and a key missing from one locale falls back to English
     * silently rather than failing anywhere visible.
     */
    public function test_the_locales_declare_the_same_validation_keys(): void
    {
        $english = $this->dottedKeys(require $this->basePath('lang/en/validation.php'));
        $romanian = $this->dottedKeys(require $this->basePath('lang/ro/validation.php'));

        sort($english);
        sort($romanian);

        $this->assertSame($english, $romanian);
    }

    public function test_the_locales_declare_the_same_api_keys(): void
    {
        $english = $this->dottedKeys(require $this->basePath('lang/en/api.php'));
        $romanian = $this->dottedKeys(require $this->basePath('lang/ro/api.php'));

        sort($english);
        sort($romanian);

        $this->assertSame($english, $romanian);
    }
}
