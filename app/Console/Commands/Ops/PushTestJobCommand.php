<?php

namespace App\Console\Commands\Ops;

use App\Jobs\TestQueueJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Pushes inert test jobs through the configured queue to verify the pipeline end to end: that dispatch reaches the store,
 * that a worker (queue:work or Horizon) picks the jobs up, and - with --fail - that the failure path records into
 * failed_jobs and the Horizon dashboard.
 *
 * Each job logs a 'queue:push-test job processed' line on success, so the round trip is observable in the application log;
 * in Horizon the jobs are tagged 'queue-test' plus their printed label.
 */
#[Signature('queue:push-test
    {--count=1 : How many jobs to push}
    {--queue= : Queue to push onto (defaults to the connection\'s queue)}
    {--sleep=0 : Seconds each job sleeps, to simulate work}
    {--fail : Make the jobs throw, to exercise the failure path}')]
#[Description('Push inert test jobs to the queue to verify workers process them')]
class PushTestJobCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = (int) $this->option('count');
        $sleepSeconds = (int) $this->option('sleep');

        if ($count < 1 || $sleepSeconds < 0) {
            $this->error('--count must be at least 1 and --sleep cannot be negative.');

            return self::FAILURE;
        }

        $queue = $this->option('queue');
        $shouldFail = (bool) $this->option('fail');
        $label = 'test-'.Str::lower(Str::random(8));

        for ($i = 1; $i <= $count; $i++) {
            $job = new TestQueueJob(
                label: "{$label}-{$i}",
                sleepSeconds: $sleepSeconds,
                shouldFail: $shouldFail,
            );

            if ($queue !== null) {
                $job->onQueue((string) $queue);
            }

            dispatch($job);
        }

        $connection = (string) config('queue.default');
        $queueName = (string) ($queue ?? config("queue.connections.{$connection}.queue", 'default'));

        $this->info("Pushed {$count} ".Str::plural('job',
                $count)." labeled '{$label}-*' onto queue '{$queueName}' of the '{$connection}' connection.");

        if ($shouldFail) {
            $this->comment('The jobs will throw on purpose - expect them under failed jobs.');
        }

        $this->comment("Watch for 'queue:push-test job processed' in the log to confirm a worker handled them.");

        return self::SUCCESS;
    }
}
