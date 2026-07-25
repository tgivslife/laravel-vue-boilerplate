<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Inert job pushed by queue:push-test to exercise the queue pipeline: dispatch, worker pickup, optional simulated work, and the failure path.
 * It carries scalar state only and its sole side effect is a log line.
 */
class TestQueueJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $label,
        public int $sleepSeconds = 0,
        public bool $shouldFail = false,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->sleepSeconds > 0) {
            sleep($this->sleepSeconds);
        }

        if ($this->shouldFail) {
            throw new RuntimeException("queue:push-test job '{$this->label}' failed on purpose.");
        }

        Log::info('queue:push-test job processed', [
            'label' => $this->label,
            'slept_seconds' => $this->sleepSeconds,
        ]);
    }

    /**
     * Tags the job carries in the Horizon dashboard.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['queue-test', $this->label];
    }
}
