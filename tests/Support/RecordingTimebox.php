<?php

namespace Tests\Support;

use Illuminate\Support\Timebox;

/**
 * A timebox that records the floor of every call and never sleeps.
 *
 * Lets a test prove that a code path ran inside the box - the floor it was given, and how many times - without paying the floor.
 * The real sleep is what the one real timing test is for.
 */
final class RecordingTimebox extends Timebox
{
    /**
     * The floor, in microseconds, of each call() in order.
     *
     * @var list<int>
     */
    public array $floors = [];

    public function call(callable $callback, int $microseconds)
    {
        $this->floors[] = $microseconds;

        return parent::call($callback, $microseconds);
    }

    protected function usleep(int $microseconds): void
    {
    }
}
