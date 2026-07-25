<?php

namespace Tests\Feature\Rules;

use App\Rules\NotCommonPassword;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class NotCommonPasswordTest extends TestCase
{
    private function passes(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => [new NotCommonPassword()]],
        )->passes();
    }

    public function test_rejects_a_listed_password(): void
    {
        $this->assertFalse($this->passes('password'));
        $this->assertFalse($this->passes('qwerty123456'));
    }

    public function test_rejects_regardless_of_case(): void
    {
        $this->assertFalse($this->passes('PaSsWoRd'));
    }

    public function test_rejects_a_listed_base_behind_trailing_decoration(): void
    {
        $this->assertFalse($this->passes('Password2026!'));
        $this->assertFalse($this->passes('sunshine1234!!'));
    }

    public function test_rejects_the_lists_boundary_entries(): void
    {
        $listPath = resource_path((string) config('security.password_policy.common_list'));
        $lines = file($listPath, FILE_IGNORE_NEW_LINES);

        $this->assertFalse($this->passes($lines[0]), 'first line of the list must be found');
        $this->assertFalse($this->passes($lines[count($lines) - 1]), 'last line of the list must be found');
        $this->assertFalse($this->passes($lines[intdiv(count($lines), 2)]), 'middle line of the list must be found');
    }

    public function test_accepts_an_unlisted_password(): void
    {
        $this->assertTrue($this->passes('valid horse battery staple'));
        $this->assertTrue($this->passes('Str4ngeUnl1stedPhr@se'));
    }

    public function test_ignores_short_stripped_bases(): void
    {
        // The stripped base ("a") is below the minimum candidate length and the full value is unlisted.
        $this->assertTrue($this->passes('a9481027365102!'));
    }
}
