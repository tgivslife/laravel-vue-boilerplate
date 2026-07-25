<?php

namespace App\Console\Commands\Ops;

use App\Console\Commands\Concerns\DrivesSentinelStack;
use App\Services\Ops\HealthCheckService;
use App\Support\Redis\SentinelFailoverException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * End-to-end drill of the Sentinel topology against the dev compose stack.
 *
 * The unit tests prove the discovery and retry logic in isolation; this command proves the whole
 * chain against real containers, driving every reachable state and asserting the app-side behavior in each:
 *
 *  1. baseline           - fresh discovery, write/read on the discovered master
 *  2. one sentinel down  - discovery skips the dead sentinel
 *  3. two sentinels down - discovery still works through the last one
 *  4. all sentinels down - cached discovery keeps THIS process serving; a fresh process
 *                          fails fast naming every sentinel (probed via a subprocess)
 *  5. replica down       - writes unaffected
 *  6. controlled failover- a connection held OPEN across `SENTINEL FAILOVER` heals in-command
 *                          (the demoted master answers READONLY; the retry loop rediscovers)
 *  7. hard master death  - `docker pause` on the master (container stop would tear down the
 *                          shared network namespace); COLD connections are hammered across the whole
 *                          election, proving they stay inside the retry deadline and that one opened while
 *                          the master was still frozen heals across the promotion - the php-fpm case, where
 *                          there is no open connection to heal because every request builds its own
 *
 * Write-loss audit: a monotonic counter is RPUSHed at every state transition (through the
 * dedicated connection classes), and the final scenario verifies Redis holds the complete
 * sequence - proof that no acknowledged write was lost across any outage or failover. Each
 * transition also runs and logs the redis-backed health probes (sessions/cache/horizon,
 * informational only), showing what ops would see in that exact state. A missing
 * number would mean an acknowledged-then-lost write (Redis replication is asynchronous, so a
 * write acknowledged by a master that dies before replicating CAN legitimately vanish - the drill
 * gates hard death on a synced replica precisely to keep the expected answer "none lost").
 *
 * Afterward, the stack is restored to its canonical state - all five containers running,
 * 6380 master, 6381 replica - in a finally block, even when a scenario fails; --keep-state skips
 * the restore. Dev tooling: refused in production, requires REDIS_TOPOLOGY=sentinel and the
 * `sentinel` compose profile running. Verified by running it, per the house rule for drills.
 *
 * `--probe` is the internal subprocess mode used by scenario 4: connect fresh, print a verdict, exit - nothing else.
 */
#[Signature('redis:sentinel-drill {--probe : Internal: fresh-process discovery probe} {--keep-state : Skip the restore, leave the stack as the last scenario left it}')]
#[Description('Drill the Sentinel topology through every reachable state against the dev compose stack')]
class SentinelDrillCommand extends Command
{
    use DrivesSentinelStack;

    /** The list key holding the transition counter sequence. */
    private const string SEQUENCE_KEY = 'sentinel-drill:sequence';

    /** @var list<string> */
    private array $failures = [];

    /** Monotonic transition counter. */
    private int $sequence = 0;

    /** @var list<int> Counters redis acknowledged - the audit expects exactly these. */
    private array $acknowledged = [];

    /** @var array<int, string> Counters whose write threw (never acknowledged), by stage. */
    private array $unacknowledged = [];

    private HealthCheckService $health;

    public function handle(HealthCheckService $health): int
    {
        $this->health = $health;

        if ($this->laravel->isProduction()) {
            $this->error('This drill manipulates docker containers - dev environments only.');

            return self::FAILURE;
        }

        if ($this->option('probe')) {
            return $this->probe();
        }

        if (config('database.redis.client') !== 'phpredis-sentinel') {
            $this->error('REDIS_TOPOLOGY is not sentinel. Run with the topology env set, e.g.:');
            $this->line('  $env:REDIS_TOPOLOGY="sentinel"; $env:REDIS_SENTINEL_HOSTS="127.0.0.1:26379,127.0.0.1:26380,127.0.0.1:26381"; php artisan redis:sentinel-drill');

            return self::FAILURE;
        }

        if (!$this->stackIsRunning()) {
            $this->error('The sentinel compose profile is not running. Start it with:');
            $this->line('  docker compose -f '.self::COMPOSE_FILE.' --profile sentinel up -d');

            return self::FAILURE;
        }

        /*
         * A --keep-state run (or one that died mid-scenario) can leave a node container paused. Diagnosing that
         * as "unrepairable pair" and telling the operator to recreate the stack would be both wrong and
         * destructive, and every role query against a frozen redis-server has to wait out its own timeout
         * first. Unpause before looking, exactly as the restore path does.
         */
        $this->unpauseNodes();

        if (!$this->ensureHealthyPair('preflight')) {
            $this->error('The master/replica pair is not healthy and could not be repaired.');
            $this->line('  Recreate the stack: docker compose -f '.self::COMPOSE_FILE.' --profile sentinel up -d --force-recreate');

            return self::FAILURE;
        }

        try {
            $this->scenarioBaseline();
            $this->scenarioSentinelOutages();
            $this->scenarioReplicaOutage();
            $this->scenarioControlledFailover();
            $this->scenarioHardMasterDeath();
            $this->scenarioSequenceAudit();
        } finally {
            if ($this->option('keep-state')) {
                $this->warn('--keep-state: skipping restore; the stack stays as the last scenario left it.');
            } else {
                $this->restoreCanonicalState();
            }
        }

        if ($this->failures !== []) {
            $this->error(sprintf('%d scenario assertion(s) failed:', count($this->failures)));

            foreach ($this->failures as $failure) {
                $this->error("  - {$failure}");
            }

            return self::FAILURE;
        }

        $this->info('All sentinel drill scenarios passed.');

        return self::SUCCESS;
    }

    /*
    |--------------------------------------------------------------------------
    | Scenarios
    |--------------------------------------------------------------------------
    */

    private function scenarioBaseline(): void
    {
        $this->line('');
        $this->info('[1/6] Baseline: fresh discovery, write/read on the discovered master');

        [$host, $port] = $this->currentMaster();
        $this->line("      sentinel reports master {$host}:{$port}");

        // A previous run's sequence would corrupt this run's audit.
        $this->freshConnection();
        Redis::connection('default')->del(self::SEQUENCE_KEY);

        // Every subsequent assertion goes through Redis::connection() and therefore through the
        // dedicated classes - but only if the driver wiring is intact, so pin that first: a
        // silent fallback to stock phpredis would pass most scenarios while testing nothing.
        $this->check('the dedicated sentinel connection class is in play', function (): bool {
            $this->freshConnection();

            return Redis::connection('default') instanceof \App\Support\Redis\PhpRedisSentinelConnection;
        });

        $this->check('baseline round-trip', function (): bool {
            $this->freshConnection();
            $key = 'sentinel-drill:'.uniqid();

            Redis::connection('default')->set($key, 'ok');
            $value = Redis::connection('default')->get($key);
            Redis::connection('default')->del($key);

            return $value === 'ok';
        });

        $this->writeCounter('baseline');
    }

    private function scenarioSentinelOutages(): void
    {
        $this->line('');
        $this->info('[2/6] Sentinel outages: partial, then total');

        $services = array_keys(self::SENTINEL_SERVICES);

        try {
            $this->compose('stop', $services[0]);
            $this->check('discovery skips one dead sentinel', fn(): bool => $this->freshRoundTrip());
            $this->writeCounter('one-sentinel-down');

            $this->compose('stop', $services[1]);
            $this->check('discovery survives on the last sentinel', fn(): bool => $this->freshRoundTrip());
            $this->writeCounter('two-sentinels-down');

            $this->compose('stop', $services[2]);

            // This process discovered the master while sentinels were alive: the per-process
            // address cache must keep it serving - sentinel loss is not redis loss.
            $this->check('cached discovery keeps a running process serving', fn(): bool => $this->freshRoundTrip());
            $this->writeCounter('all-sentinels-down-cached');

            // A fresh process has no cache: it must fail fast, naming every sentinel it tried.
            $probe = $this->runProbeSubprocess();
            $this->check(
                'a fresh process fails fast naming every sentinel',
                fn(): bool => str_contains($probe, 'PROBE-FAIL')
                    && str_contains($probe, '26379')
                    && str_contains($probe, '26380')
                    && str_contains($probe, '26381'),
                "probe output: {$probe}"
            );
        } finally {
            // Quietly: compose() throws, and a throw from a finally would replace whatever failure got us here.
            $this->composeQuietly('start', ...$services);
            $this->waitFor(fn(): bool => $this->sentinelsAnswering() === count($services), 30, 'sentinels back up');
        }

        $this->writeCounter('sentinels-restored');
    }

    private function scenarioReplicaOutage(): void
    {
        $this->line('');
        $this->info('[3/6] Replica outage: writes unaffected');

        [, $masterPort] = $this->currentMaster();

        $this->assertPortBelongsToStack($masterPort);

        // pause, never stop: after a failover the replica ROLE may live in the
        // redis-sentinel-master CONTAINER, which owns the stack's shared network namespace -
        // stopping it would sever every other container. Pausing freezes only the process.
        $replicaService = self::NODE_SERVICES[$masterPort === 6380 ? 6381 : 6380];

        try {
            $this->compose('pause', $replicaService);
            $this->check('writes keep landing on the master without its replica', fn(): bool => $this->freshRoundTrip());
            $this->writeCounter('replica-paused');
        } finally {
            $this->compose('unpause', $replicaService);
        }
    }

    private function scenarioControlledFailover(): void
    {
        $this->line('');
        $this->info('[4/6] Controlled failover: a live connection heals in-command');

        [, $portBefore] = $this->currentMaster();

        $this->waitFor(fn(): bool => $this->replicaIsOnline($portBefore), 45, 'the replica to finish syncing');

        // Open and USE the connection before the failover so it holds a socket to the old master.
        $this->freshConnection();
        $connection = Redis::connection('default');
        $connection->ping();

        $this->writeCounter('pre-controlled-failover', $connection);

        $this->issueFailover();

        $this->waitFor(function () use ($portBefore): bool {
            [, $port] = $this->currentMaster();

            return $port !== $portBefore;
        }, 20, 'promotion to the other node');

        /*
         * Sentinel's view flips BEFORE the old master is actually demoted. A write in that window
         * lands on the dying master without any error and is wiped when it resyncs as a replica -
         * Redis's documented failover lost-write window (min-replicas-to-write narrows it in
         * production; an earlier drill run lost counter #8 to exactly this). Waiting for the old
         * master to report role:slave puts the held connection deterministically in READONLY
         * territory, which is the healing path this scenario exists to prove.
         */
        $this->waitFor(fn(): bool => $this->nodeRole($portBefore) === 'slave', 20, 'the old master to be demoted (role:slave)');

        [, $portAfter] = $this->currentMaster();
        $this->line("      promoted: {$portBefore} -> {$portAfter}");

        // The demoted master answers READONLY; PhpRedisSentinelConnection must rediscover and
        // retry inside this very call - zero visible failure is the acceptance bar.
        $healMs = 0;

        $this->check('the held connection heals inside the failing command', function () use ($connection, &$healMs): bool {
            $key = 'sentinel-drill:failover:'.uniqid();
            $startedAt = microtime(true);

            // First command on the held connection since the demotion, so this is the one that pays for the
            // rediscovery and the retry - everything after it is talking to the new master already.
            $connection->set($key, 'ok');

            $healMs = (int) ((microtime(true) - $startedAt) * 1000);

            $value = $connection->get($key);
            $connection->del($key);

            return $value === 'ok';
        });

        // Without a bound the drill would pass just as happily on a heal that took half a minute, which is the
        // failure mode the retry deadline exists to prevent.
        $deadlineMs = $this->retryDeadlineMs();

        $this->check(
            'the heal stayed inside the retry deadline',
            fn(): bool => $healMs > 0 && $healMs <= $deadlineMs,
            "heal took {$healMs}ms, deadline {$deadlineMs}ms",
        );

        $this->writeCounter('post-failover-held-connection', $connection);
    }

    private function scenarioHardMasterDeath(): void
    {
        $this->line('');
        $this->info('[5/6] Hard master death: cold connections across the election, then automatic promotion');

        [, $portBefore] = $this->currentMaster();

        $service = self::NODE_SERVICES[$this->assertPortBelongsToStack($portBefore)];

        // Without a synced replica there is nothing to promote and the scenario would just
        // time out - same precondition a production failover has. The synced replica is also
        // what entitles the audit to expect the pre-death counter to survive the promotion.
        $this->waitFor(fn(): bool => $this->replicaIsOnline($portBefore), 45, 'the replica to finish syncing');

        $this->writeCounter('pre-hard-death');

        try {
            // pause, not stop: the master container owns the stack's shared network namespace,
            // stopping it would sever every sentinel too. Paused = frozen process, dead socket.
            $this->compose('pause', $service);

            $this->probeColdConnectionsUntilPromoted($portBefore);

            [, $portAfter] = $this->currentMaster();
            $this->line("      promoted: {$portBefore} -> {$portAfter}");

            $this->check('the app serves from the promoted master', fn(): bool => $this->freshRoundTrip());
            $this->writeCounter('post-hard-death');
        } finally {
            $this->compose('unpause', $service);
        }
    }

    /**
     * The write-loss audit: Redis must hold every counter it acknowledged, in order.
     *
     * The counters were written through every state the drill produced - a missing number is an
     * acknowledged-then-lost write. Given hard death is gated on a synced replica, the expected
     * result is zero losses; a loss here with that gate in place points at the failover path,
     * not at Redis's (asynchronous, documented) replication semantics.
     */
    private function scenarioSequenceAudit(): void
    {
        $this->line('');
        $this->info('[6/6] Sequence audit: every acknowledged counter survived every transition');

        $this->freshConnection();
        $entries = Redis::connection('default')->lrange(self::SEQUENCE_KEY, 0, -1) ?: [];

        $found = array_map(static fn(string $entry): int => (int) strtok($entry, ':'), $entries);
        $missing = array_values(array_diff($this->acknowledged, $found));

        $this->line(sprintf(
            '      acknowledged %d counters (%s), found %d in redis',
            count($this->acknowledged),
            $this->acknowledged === [] ? '-' : '#1-#'.max($this->acknowledged),
            count($found),
        ));

        foreach ($entries as $entry) {
            $this->line("        {$entry}");
        }

        if ($this->unacknowledged !== []) {
            foreach ($this->unacknowledged as $number => $stage) {
                $this->warn("      #{$number} ({$stage}) was never acknowledged - correctly absent from the audit");
            }
        }

        $this->check(
            'redis holds the complete acknowledged sequence',
            fn(): bool => $missing === [] && count($this->acknowledged) > 0,
            $missing === [] ? '' : 'missing: #'.implode(', #', $missing)
        );

        if ($missing === []) {
            Redis::connection('default')->del(self::SEQUENCE_KEY);
        } else {
            $this->warn('      keeping '.self::SEQUENCE_KEY.' in redis (db0) for inspection');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Probe subprocess (scenario 4's fresh process)
    |--------------------------------------------------------------------------
    */

    private function probe(): int
    {
        try {
            $connection = Redis::connection('default');
            $connection->ping();

            $this->line('PROBE-OK '.get_class($connection));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line('PROBE-FAIL '.get_class($exception).': '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function runProbeSubprocess(): string
    {
        $result = Process::timeout(30)->run([
            PHP_BINARY, base_path('artisan'), 'redis:sentinel-drill', '--probe',
        ]);

        return trim($result->output().$result->errorOutput());
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * Record and report one assertion.
     */
    private function check(string $label, callable $assertion, string $detail = ''): void
    {
        try {
            $passed = (bool) $assertion();
        } catch (Throwable $exception) {
            $passed = false;
            $detail = trim($detail.' '.get_class($exception).': '.$exception->getMessage());
        }

        if ($passed) {
            $this->line("      [PASS] {$label}");

            return;
        }

        $this->error("      [FAIL] {$label}".($detail !== '' ? " - {$detail}" : ''));
        $this->failures[] = $label.($detail !== '' ? " ({$detail})" : '');
    }

    /**
     * Write the next transition counter, labeled with the state that produced it.
     *
     * Goes through the dedicated connection classes like every other drill operation - through
     * the given held connection when provided, through a pooled one otherwise. Only acknowledged
     * writes join the audit's expectations; a write that throws is recorded as unacknowledged
     * (nothing to audit - redis never confirmed it).
     */
    private function writeCounter(string $stage, ?\Illuminate\Redis\Connections\Connection $connection = null): void
    {
        $number = ++$this->sequence;

        try {
            ($connection ?? Redis::connection('default'))->rpush(self::SEQUENCE_KEY, "{$number}:{$stage}");

            $this->acknowledged[] = $number;
            $this->line("      counter #{$number} written ({$stage})");
        } catch (Throwable $exception) {
            $this->unacknowledged[$number] = $stage;
            $this->warn("      counter #{$number} ({$stage}) NOT acknowledged: {$exception->getMessage()}");
        }

        $this->logHealthProbes($stage);
    }

    /**
     * Run the redis-backed health probes and log what ops would see in this state.
     *
     * Strictly informational - never a drill assertion: probe outcomes legitimately vary with
     * the state (and with whether horizon-sentinel happens to be running).
     *
     * The reading to watch for is a *divergence*: through a total sentinel outage the sessions and cache
     * probes stay green, because warm-cache discovery keeps serving off the master this process already
     * found, while `sentinel` goes red. That gap - redundancy gone, serving unaffected, nothing on the
     * dashboard saying so - is the entire reason the sentinel probe exists, and this is the only place it can
     * be seen happening.
     */
    private function logHealthProbes(string $stage): void
    {
        try {
            $probes = $this->health->report(only: ['sessions', 'cache', 'sentinel', 'horizon']);
        } catch (Throwable $exception) {
            $this->warn("      [health@{$stage}] report failed: {$exception->getMessage()}");

            return;
        }

        if ($probes === []) {
            $this->line("      [health@{$stage}] no applicable probes (sessions/cache/horizon)");

            return;
        }

        foreach ($probes as $probe) {
            $line = sprintf(
                '      [health@%s] %s: %s - %s (%sms)',
                $stage,
                $probe['name'],
                $probe['ok'] ? 'OK' : 'FAIL',
                $probe['detail'],
                $probe['duration_ms'],
            );

            $probe['ok'] ? $this->line($line) : $this->warn($line);
        }
    }

    /**
     * Drop the pooled connection so the next Redis::connection() reconnects
     * (and rediscovers through the retry path when the cached address is stale).
     */
    private function freshConnection(): void
    {
        $this->laravel['redis']->purge('default');
    }

    private function freshRoundTrip(): bool
    {
        $this->freshConnection();
        $key = 'sentinel-drill:'.uniqid();

        Redis::connection('default')->set($key, 'ok');
        $value = Redis::connection('default')->get($key);
        Redis::connection('default')->del($key);

        return $value === 'ok';
    }

    /**
     * Hammer *cold* connections while the master is frozen, until the sentinels finish promoting.
     *
     * This is the half of failover handling the in-command retry loop structurally cannot reach: under php-fpm
     * every request builds its four connections from scratch, so the request that arrives mid-election has no
     * open connection to heal - it has to heal while connecting. Two properties are asserted, deliberately
     * different in kind:
     *
     *  - EVERY attempt returns inside the deadline (plus slack for the socket timeouts around it). This one
     *    must always hold: a request may legitimately fail during an election, but it must never hang, and it
     *    must never find that out by reaching max_execution_time.
     *  - At least one attempt that STARTED while the sentinels still named the frozen node returned ok anyway.
     *    That is connect-time retry actually working. It holds for attempts beginning within one deadline of
     *    the promotion, which is why this hammers rather than samples - with `down-after-milliseconds 5000`
     *    and a 5s budget, an attempt at t=0 cannot possibly span the election and one at t≈5s comfortably does.
     */
    private function probeColdConnectionsUntilPromoted(int $portBefore): void
    {
        $this->line('      probing cold connections until promotion (max 60s)...');

        $deadlineMs = $this->retryDeadlineMs();
        $slackMs = 1000 + (int) (2000 * (float) config('database.redis.default.timeout', 2.0));

        $slowestMs = 0;
        $healedWhileDown = false;
        $unexpected = [];
        $promoted = false;
        $probes = 0;
        $stopBy = microtime(true) + 60;

        do {
            [, $portAtStart] = $this->currentMaster();
            $startedDown = $portAtStart === $portBefore;

            $startedAt = microtime(true);

            try {
                $ok = $this->freshRoundTrip();
            } catch (Throwable $exception) {
                $ok = false;

                if (!$exception instanceof SentinelFailoverException) {
                    $unexpected[] = get_class($exception).': '.$exception->getMessage();
                }
            }

            $elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);
            $slowestMs = max($slowestMs, $elapsedMs);
            $probes++;

            $this->line(sprintf(
                '        probe #%d: %s in %dms (master %s at start)',
                $probes,
                $ok ? 'ok' : 'failed',
                $elapsedMs,
                $startedDown ? 'still frozen' : 'already promoted',
            ));

            if ($ok && $startedDown) {
                $healedWhileDown = true;
            }

            [, $portNow] = $this->currentMaster();
            $promoted = $portNow !== $portBefore;
        } while (!$promoted && microtime(true) < $stopBy);

        $this->check(
            'automatic promotion happened',
            fn(): bool => $promoted,
            'sentinels never named a different master',
        );

        $this->check(
            'every cold connection returned inside the retry deadline',
            fn(): bool => $slowestMs <= $deadlineMs + $slackMs,
            "slowest {$slowestMs}ms over {$probes} probes, deadline {$deadlineMs}ms + {$slackMs}ms slack",
        );

        $this->check(
            'cold-connection failures are bounded, typed give-ups',
            fn(): bool => $unexpected === [],
            implode('; ', $unexpected),
        );

        $this->check(
            'a cold connection opened while the master was frozen healed across the promotion',
            fn(): bool => $healedWhileDown,
            "no probe both started while the sentinels still named {$portBefore} and succeeded",
        );
    }

    private function retryDeadlineMs(): int
    {
        return (int) config('database.redis.default.retry_deadline', 5000);
    }

}
