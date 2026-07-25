<?php

namespace App\Console\Commands\Ops;

use App\Console\Commands\Concerns\DrivesSentinelStack;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Walks the Sentinel stack through every reachable state and dwells in each, asserting nothing.
 *
 * The counterpart to {@see SentinelDrillCommand}, and deliberately not a flag on it. The drill is a verdict:
 * `exit 0` means every app-side guarantee held, which stops being a useful promise the moment the same command
 * can also be told not to check. It is also the wrong instrument for watching something else happen - it writes
 * sequence counters, runs the health probes at every transition and spawns a discovery subprocess, all of which
 * is Redis traffic competing with whatever you are trying to observe. This command opens no application Redis
 * connection at all; it only drives docker and reads the topology through redis-cli, so the only client
 * touching Redis during the walk is the one you are watching.
 *
 * Intended use: start something (`queue:push-test --count=100 --sleep=2` into a running Horizon), run this
 * alongside it, and watch how that something behaves as the topology moves underneath it. Each transition
 * prints a timestamped banner with the elapsed clock, so the output lines up against Horizon's dashboard, the
 * application log and the failed_jobs table.
 *
 * Hold times are split because the states are not equally interesting. Losing one or two sentinels does not
 * touch the data path at all - clients already hold a master address and keep using it - so those get the
 * brief hold. The three that actually bite get the full one:
 *
 *  - all sentinels down: warm processes keep serving off cached discovery, but any process that restarts
 *    (a Horizon worker recycling) cannot resolve a master at all
 *  - controlled failover: the demoted master answers READONLY mid-flight
 *  - hard master death: a real election, with a window where no master exists anywhere
 *
 * The stack is restored to canonical state afterwards, in a finally, even if a transition throws;
 * --keep-state skips that. Dev tooling: refused in production, requires REDIS_TOPOLOGY=sentinel.
 */
#[Signature('redis:sentinel-states
    {--hold=30 : Seconds to dwell in each state that affects the data path}
    {--brief-hold=8 : Seconds to dwell in states that do not (losing individual sentinels)}
    {--skip=* : State names to skip (see the banners; e.g. --skip=replica-down)}
    {--keep-state : Skip the restore, leave the stack as the last state left it}')]
#[Description('Walk the Sentinel stack through its states, holding each, so queue/app behaviour can be observed')]
class SentinelStatesCommand extends Command
{
    use DrivesSentinelStack;

    private float $startedAt = 0.0;

    /** @var list<string> */
    private array $visited = [];

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('This command manipulates docker containers - dev environments only.');

            return self::FAILURE;
        }

        if (config('database.redis.client') !== 'phpredis-sentinel') {
            $this->error('REDIS_TOPOLOGY is not sentinel; there are no sentinel states to walk.');

            return self::FAILURE;
        }

        if (!$this->stackIsRunning()) {
            $this->error('The sentinel compose profile is not running. Start it with:');
            $this->line('  docker compose -f '.self::COMPOSE_FILE.' --profile sentinel up -d');

            return self::FAILURE;
        }

        if ((int) $this->option('hold') < 0 || (int) $this->option('brief-hold') < 0) {
            $this->error('Hold times cannot be negative.');

            return self::FAILURE;
        }

        $this->unpauseNodes();

        if (!$this->ensureHealthyPair('preflight')) {
            $this->error('The master/replica pair is not healthy and could not be repaired.');
            $this->line('  Recreate the stack: docker compose -f '.self::COMPOSE_FILE.' --profile sentinel up -d --force-recreate');

            return self::FAILURE;
        }

        $this->startedAt = microtime(true);

        $this->line('');
        $this->info('Walking the sentinel states. Nothing here asserts anything - watch your own workload.');

        try {
            $this->walkBaseline();
            $this->walkSentinelOutages();
            $this->walkReplicaOutage();
            $this->walkControlledFailover();
            $this->walkHardMasterDeath();
        } finally {
            if ($this->option('keep-state')) {
                $this->warn('--keep-state: skipping restore; the stack stays as the last state left it.');
            } else {
                $this->restoreCanonicalState();
            }
        }

        $this->line('');
        $this->info(sprintf('Walk complete after %s. States visited: %s.', $this->elapsed(), implode(', ', $this->visited)));

        return self::SUCCESS;
    }

    private function walkBaseline(): void
    {
        $this->enter('baseline', 'healthy pair, nothing touched', brief: true);
    }

    private function walkSentinelOutages(): void
    {
        $services = array_keys(self::SENTINEL_SERVICES);

        try {
            if ($this->enter('one-sentinel-down', 'discovery just skips it', brief: true)) {
                $this->compose('stop', $services[0]);
                $this->hold(brief: true);
            }

            if ($this->enter('two-sentinels-down', 'quorum intact, data path untouched', brief: true)) {
                $this->compose('stop', $services[1]);
                $this->hold(brief: true);
            }

            if ($this->enter('all-sentinels-down', 'warm clients keep serving; a restarting one cannot resolve a master')) {
                $this->compose('stop', $services[2]);
                $this->hold();
            }
        } finally {
            $this->composeQuietly('start', ...$services);
            $this->waitFor(fn(): bool => $this->sentinelsAnswering() === count($services), 30, 'sentinels back up');
        }
    }

    private function walkReplicaOutage(): void
    {
        if (!$this->enter('replica-down', 'writes unaffected, but no failover is possible')) {
            return;
        }

        [, $masterPort] = $this->currentMaster();
        $this->assertPortBelongsToStack($masterPort);

        $replicaService = self::NODE_SERVICES[$masterPort === 6380 ? 6381 : 6380];

        try {
            // pause, never stop: one container owns the stack's shared network namespace.
            $this->compose('pause', $replicaService);
            $this->hold();
        } finally {
            $this->compose('unpause', $replicaService);
        }
    }

    private function walkControlledFailover(): void
    {
        if (!$this->enter('controlled-failover', 'orderly promotion; the demoted master answers READONLY')) {
            return;
        }

        [, $portBefore] = $this->currentMaster();

        $this->waitFor(fn(): bool => $this->replicaIsOnline($portBefore), 45, 'the replica to finish syncing');
        $this->issueFailover();

        $this->waitFor(function () use ($portBefore): bool {
            [, $port] = $this->currentMaster();

            return $port !== $portBefore;
        }, 20, 'promotion to the other node');

        [, $portAfter] = $this->currentMaster();
        $this->line("      promoted: {$portBefore} -> {$portAfter}");

        $this->hold();
    }

    private function walkHardMasterDeath(): void
    {
        if (!$this->enter('hard-master-death', 'no master exists anywhere until the election completes')) {
            return;
        }

        [, $portBefore] = $this->currentMaster();
        $service = self::NODE_SERVICES[$this->assertPortBelongsToStack($portBefore)];

        // Sentinel will not promote without a synced replica, so the state would just be an outage.
        $this->waitFor(fn(): bool => $this->replicaIsOnline($portBefore), 45, 'the replica to finish syncing');

        try {
            $this->compose('pause', $service);

            $this->waitFor(function () use ($portBefore): bool {
                [, $port] = $this->currentMaster();

                return $port !== $portBefore;
            }, 30, 'automatic promotion (down-after 5s + election)');

            [, $portAfter] = $this->currentMaster();
            $this->line("      promoted: {$portBefore} -> {$portAfter}");

            $this->hold();
        } finally {
            $this->compose('unpause', $service);
        }
    }

    /**
     * Announce a state, unless it was skipped.
     *
     * @return bool Whether to actually enter it.
     */
    private function enter(string $state, string $note, bool $brief = false): bool
    {
        if (in_array($state, (array) $this->option('skip'), true)) {
            $this->line(sprintf('  [%s] %-22s skipped', $this->elapsed(), $state));

            return false;
        }

        $this->visited[] = $state;

        $this->line('');
        $this->info(sprintf('  [%s] %s', $this->elapsed(), $state));
        $this->line("      {$note}");
        $this->line('      master: '.$this->describeMaster());

        // The baseline has nothing to set up, so it holds here rather than in a caller.
        if ($state === 'baseline') {
            $this->hold(brief: $brief);
        }

        return true;
    }

    /**
     * Dwell in the current state, so whatever is being observed has time to run through it.
     */
    private function hold(bool $brief = false): void
    {
        $seconds = (int) $this->option($brief ? 'brief-hold' : 'hold');

        if ($seconds <= 0) {
            return;
        }

        $this->line("      holding {$seconds}s (master: ".$this->describeMaster().')');

        sleep($seconds);
    }

    /**
     * The master the sentinels currently name, or why they cannot say.
     */
    private function describeMaster(): string
    {
        try {
            [$host, $port] = $this->currentMaster();

            return "{$host}:{$port}";
        } catch (RuntimeException $exception) {
            return 'unknown ('.$exception->getMessage().')';
        }
    }

    private function elapsed(): string
    {
        $seconds = (int) (microtime(true) - $this->startedAt);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
