<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Drives the `sentinel` compose profile: container control, topology inspection and repair.
 *
 * Shared by the two commands that manipulate the stack - {@see \App\Console\Commands\Ops\SentinelDrillCommand},
 * which asserts app-side behaviour in each state, and {@see \App\Console\Commands\Ops\SentinelStatesCommand},
 * which only walks the states so something else can be observed through them. Neither may own this logic
 * privately: it encodes hard-won details about the stack that are wrong in non-obvious ways when duplicated -
 * that pausing beats stopping because one container owns the shared network namespace, that redis-cli needs an
 * external timeout because it has no reply timeout of its own, and how to recognise and repair the two broken
 * pair states a chaotic run can leave behind.
 *
 * A trait rather than a service so the console helpers ($this->line, $this->warn) stay available; nothing here
 * touches the application's own Redis connections, only docker.
 */
trait DrivesSentinelStack
{
    protected const string COMPOSE_FILE = 'docker/compose.dev.yaml';

    protected const array SENTINEL_SERVICES = ['redis-sentinel-1' => 26379, 'redis-sentinel-2' => 26380, 'redis-sentinel-3' => 26381];

    /** Data-node service by the port its redis-server listens on. */
    protected const array NODE_SERVICES = [6380 => 'redis-sentinel-master', 6381 => 'redis-sentinel-replica'];

    /** Seconds any single in-container redis-cli query may take before it is killed. */
    protected const int QUERY_TIMEOUT = 3;

    /**
     * Bring the stack back to its canonical shape: everything running, 6380 master, 6381 replica.
     */
    protected function restoreCanonicalState(): void
    {
        $this->line('');
        $this->info('Restoring canonical state (all containers up, 6380 master)...');

        foreach (array_keys(self::NODE_SERVICES) as $port) {
            $this->composeQuietly('unpause', self::NODE_SERVICES[$port]);
        }

        $this->composeQuietly('start', ...array_keys(self::NODE_SERVICES), ...array_keys(self::SENTINEL_SERVICES));
        $this->waitFor(fn(): bool => $this->sentinelsAnswering() > 0, 30, 'a sentinel answering');

        // Fail back until 6380 holds the master role. Sentinel refuses a failover while the
        // candidate replica is still syncing, so this retries rather than fires once.
        $restored = $this->waitFor(function (): bool {
            [, $port] = $this->currentMaster();

            if ($port === 6380) {
                return true;
            }

            try {
                $this->sentinelCommand('sentinel', 'failover', 'mymaster');
            } catch (RuntimeException) {
                // NOGOODSLAVE while 6380 is still syncing - wait and retry.
            }

            return false;
        }, 60, 'failback to 6380');

        $restored = $restored && $this->ensureHealthyPair('restore');

        $restored
            ? $this->info('Canonical state restored: 6380 master, 6381 replica online, 3 sentinels.')
            : $this->warn('Could not confirm a healthy 6380-master pair - check the stack manually (sentinel get-master-addr-by-name mymaster, info replication on 6380/6381).');
    }

    /**
     * Verify - and where possible repair - the master/replica pair.
     *
     * Chaotic container restarts can leave the pair in a circular deadlock (each node a replica
     * of the other, both links down, everything READONLY while the sentinels report a stale
     * master). That state never resolves on its own; the repair is promoting the node the
     * sentinels already believe is the master (`REPLICAOF NO ONE`), after which the other node's
     * existing replication target becomes valid and the pair re-forms.
     *
     * The other repairable state is the mirror image: the data nodes are perfectly healthy and paired, but the
     * sentinels name the replica as master - what a failover that was announced and never completed leaves
     * behind. There the nodes need no repair at all, only the sentinels do.
     */
    protected function ensureHealthyPair(string $context): bool
    {
        return $this->waitFor(function (): bool {
            [, $masterPort] = $this->currentMaster();
            $otherPort = $masterPort === 6380 ? 6381 : 6380;

            if ($this->nodeRole($masterPort) === 'master') {
                return $this->replicaIsOnline($masterPort);
            }

            /*
             * The sentinels' pick is not actually a master. Realign the NODES onto it rather than trying to
             * change the sentinels' minds: SENTINEL RESET only re-reads the address a sentinel already
             * monitors, it does not make one follow a replica's master_host, so a stale view outlives it.
             * Promoting their pick and pointing the other node at it converges in one step, and it converges
             * on the canonical layout, because the sentinels are configured to monitor 6380 from the start.
             */
            $this->warn("      sentinels name {$masterPort}, which is not a master - realigning the pair onto it");
            $this->redisCli('redis-sentinel-master', '-p', (string) $masterPort, 'replicaof', 'no', 'one');
            $this->redisCli('redis-sentinel-master', '-p', (string) $otherPort, 'replicaof', '127.0.0.1', (string) $masterPort);

            return false;
        }, 45, "a healthy master/replica pair ({$context})");
    }

    /**
     * Unpause both data nodes, so a previous run that died mid-scenario does not look like a broken pair.
     */
    protected function unpauseNodes(): void
    {
        foreach (self::NODE_SERVICES as $service) {
            $this->composeQuietly('unpause', $service);
        }
    }

    /**
     * The role ('master'/'slave') the redis on the given port reports for itself.
     */
    protected function nodeRole(int $port): string
    {
        try {
            $info = $this->redisCli('redis-sentinel-master', '-p', (string) $port, 'info', 'replication');
        } catch (RuntimeException) {
            return 'unreachable';
        }

        preg_match('/role:(\w+)/', $info, $matches);

        return $matches[1] ?? 'unknown';
    }

    /**
     * The port, once it is known to belong to this compose stack.
     *
     * Every scenario that pauses a container derives the target from whichever port the sentinels currently
     * call master; pointed at someone else's fleet, that arithmetic would confidently pause the wrong thing.
     */
    protected function assertPortBelongsToStack(int $port): int
    {
        return isset(self::NODE_SERVICES[$port]) ? $port : throw new RuntimeException(
            "Sentinel reports the master on port {$port}, which is not part of this compose stack "
            .'(expected 6380/6381) - is REDIS_SENTINEL_HOSTS pointing at a different sentinel fleet?'
        );
    }

    /**
     * Whether the master on the given port reports an online, synced replica.
     *
     * Sentinel refuses to fail over without one (NOGOODSLAVE), so the failover paths gate on
     * this - the same precondition a production failover has. Queried through the namespace
     * owner's container, which is running whenever this is called.
     */
    protected function replicaIsOnline(int $masterPort): bool
    {
        try {
            $info = $this->redisCli('redis-sentinel-master', '-p', (string) $masterPort, 'info', 'replication');
        } catch (RuntimeException) {
            return false;
        }

        return str_contains($info, 'state=online');
    }

    /**
     * Issue `SENTINEL FAILOVER` with retry: sentinel answers NOGOODSLAVE/INPROG during sync or an
     * ongoing election, both of which resolve on their own.
     */
    protected function issueFailover(): void
    {
        $accepted = $this->waitFor(function (): bool {
            try {
                $this->sentinelCommand('sentinel', 'failover', 'mymaster');

                return true;
            } catch (RuntimeException) {
                return false;
            }
        }, 30, 'the failover to be accepted');

        if (!$accepted) {
            throw new RuntimeException('Sentinel kept refusing the failover (NOGOODSLAVE/INPROG) for 30s.');
        }
    }

    /**
     * The master address according to the first answering sentinel.
     *
     * @return array{0: string, 1: int}
     */
    protected function currentMaster(): array
    {
        foreach (self::SENTINEL_SERVICES as $service => $port) {
            try {
                $output = $this->redisCli($service, '-p', (string) $port, 'sentinel', 'get-master-addr-by-name', 'mymaster');
            } catch (RuntimeException) {
                continue;
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));

            if (count($lines) >= 2) {
                return [$lines[0], (int) $lines[1]];
            }
        }

        throw new RuntimeException('No sentinel could report the current master.');
    }

    /**
     * Run a sentinel command on the first reachable sentinel.
     */
    protected function sentinelCommand(string ...$arguments): string
    {
        $lastError = 'no sentinel reachable';

        foreach (self::SENTINEL_SERVICES as $service => $port) {
            try {
                $output = $this->redisCli($service, '-p', (string) $port, ...$arguments);
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();

                continue;
            }

            /*
             * redis-cli prints an error reply as its bare code at the start of the output. Matching the code
             * as a leading token rather than searching the whole body keeps a master name or a payload that
             * merely contains "ERR" from being read as a failure.
             */
            if (preg_match('/^\s*(ERR|NOGOODSLAVE|INPROG|NOMASTER|NOQUORUM|WRONGPASS|NOPERM|NOAUTH)\b/i', $output) === 1) {
                throw new RuntimeException(trim($output));
            }

            return $output;
        }

        throw new RuntimeException($lastError);
    }

    protected function sentinelsAnswering(): int
    {
        $answering = 0;

        foreach (self::SENTINEL_SERVICES as $service => $port) {
            try {
                $this->redisCli($service, '-p', (string) $port, 'ping');
                $answering++;
            } catch (RuntimeException) {
                // Not up yet.
            }
        }

        return $answering;
    }

    protected function stackIsRunning(): bool
    {
        try {
            return $this->sentinelsAnswering() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Poll a condition with one-second ticks; false on timeout.
     *
     * The budget is wall-clock, not a poll count, so a condition that blocks quietly eats it: a 45s budget
     * buys ~45 polls of a cheap condition and barely a handful of one that has to wait out a socket timeout.
     * The timeout message therefore reports both numbers - a timeout after 3 polls is a different bug report
     * from a timeout after 45.
     */
    protected function waitFor(callable $condition, int $seconds, string $waitingFor): bool
    {
        $this->line("      waiting for {$waitingFor} (max {$seconds}s)...");

        $startedAt = microtime(true);
        $deadline = $startedAt + $seconds;
        $polls = 0;

        do {
            $polls++;

            try {
                if ($condition()) {
                    return true;
                }
            } catch (Throwable) {
                // Condition not evaluable yet (e.g. no sentinel answering) - keep polling.
            }

            sleep(1);
        } while (microtime(true) < $deadline);

        $this->warn(sprintf(
            '      timed out waiting for %s after %d poll(s) in %.1fs',
            $waitingFor,
            $polls,
            microtime(true) - $startedAt,
        ));

        return false;
    }

    protected function compose(string ...$arguments): void
    {
        $this->line('      docker compose '.implode(' ', $arguments));

        $result = Process::timeout(60)->run([
            'docker', 'compose', '-f', base_path(self::COMPOSE_FILE), '--profile', 'sentinel', ...$arguments,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException("docker compose {$arguments[0]} failed: ".trim($result->errorOutput()));
        }
    }

    /**
     * Like compose(), but tolerant - used by the restore path where "already running" /
     * "not paused" answers are expected.
     */
    protected function composeQuietly(string ...$arguments): void
    {
        Process::timeout(60)->run([
            'docker', 'compose', '-f', base_path(self::COMPOSE_FILE), '--profile', 'sentinel', ...$arguments,
        ]);
    }

    /**
     * Run redis-cli inside a stack container, bounded on both sides.
     *
     * redis-cli has no reply timeout of its own - `-t` bounds only the connect, which succeeds instantly
     * against a `docker pause`d server whose listening socket the kernel still accepts into its backlog while
     * the frozen process never answers. So the bound has to come from outside the client: busybox `timeout`
     * (present in the redis:*-alpine images, GNU-style `timeout SECS PROG` form) makes the in-container
     * process self-terminate - SIGTERM, so exit 143, non-zero and therefore already a failure to dockerExec -
     * instead of being orphaned when a host-side kill tears down `docker compose exec`. The slightly longer
     * Process timeout above it is the backstop.
     */
    protected function redisCli(string $service, string ...$arguments): string
    {
        return $this->dockerExec(
            $service,
            self::QUERY_TIMEOUT + 3,
            'timeout', (string) self::QUERY_TIMEOUT, 'redis-cli', ...$arguments,
        );
    }

    protected function dockerExec(string $service, int $timeout, string ...$arguments): string
    {
        $result = Process::timeout($timeout)->run([
            'docker', 'compose', '-f', base_path(self::COMPOSE_FILE), '--profile', 'sentinel', 'exec', '-T', $service, ...$arguments,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "exec on {$service} failed");
        }

        return $result->output();
    }
}
