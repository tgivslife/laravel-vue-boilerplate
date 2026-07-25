<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class VerifyPasswordListCommandTest extends TestCase
{
    private string $scratchPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratchPath = tempnam(sys_get_temp_dir(), 'password-list-');
    }

    protected function tearDown(): void
    {
        @unlink($this->scratchPath);

        parent::tearDown();
    }

    public function test_the_committed_list_is_valid(): void
    {
        $this->artisan('security:verify-password-list')->assertSuccessful();
    }

    public function test_accepts_a_well_formed_file(): void
    {
        file_put_contents($this->scratchPath, "alpha\nbeta\ngamma\n");

        $this->artisan('security:verify-password-list', ['path' => $this->scratchPath])
            ->assertSuccessful();
    }

    public function test_rejects_out_of_order_entries(): void
    {
        file_put_contents($this->scratchPath, "beta\nalpha\n");

        $this->artisan('security:verify-password-list', ['path' => $this->scratchPath])
            ->expectsOutputToContain('out of byte order')
            ->assertFailed();
    }

    public function test_rejects_duplicates_uppercase_crlf_and_blanks(): void
    {
        file_put_contents($this->scratchPath, "alpha\r\nalpha\n\nBravo\n");

        $this->artisan('security:verify-password-list', ['path' => $this->scratchPath])
            ->expectsOutputToContain('CRLF line ending')
            ->expectsOutputToContain('duplicate of the previous line')
            ->expectsOutputToContain('blank line')
            ->expectsOutputToContain('not lowercase')
            ->assertFailed();
    }

    public function test_rejects_an_empty_file(): void
    {
        file_put_contents($this->scratchPath, '');

        $this->artisan('security:verify-password-list', ['path' => $this->scratchPath])
            ->expectsOutputToContain('is empty')
            ->assertFailed();
    }

    public function test_rejects_a_missing_file(): void
    {
        $this->artisan('security:verify-password-list', ['path' => $this->scratchPath.'-missing'])
            ->expectsOutputToContain('missing or unreadable')
            ->assertFailed();
    }
}
