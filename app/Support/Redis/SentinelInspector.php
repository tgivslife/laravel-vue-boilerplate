<?php

namespace App\Support\Redis;

use RedisException;
use Throwable;

/**
 * Reads the state of the sentinel fleet, for the `sentinel` health probe.
 *
 * The data-path probes structurally cannot see this. Sessions and cache keep answering perfectly well off a
 * process's cached discovery while every last sentinel is down, so redundancy can be gone for hours with
 * nothing on the dashboard turning red - and the first anyone hears of it is the failover that never happens.
 * This asks the fleet directly: how many answer, whether they still agree on a master, whether they think a
 * quorum is reachable, and whether there is a healthy replica to promote at all.
 *
 * Off the request path by design - the probe is non-critical and never gates /up, so it can afford to talk to
 * every configured sentinel rather than stopping at the first that answers.
 *
 * @phpstan-type FleetReport array{configured: int, answering: int, quorum: bool, masters: array<string, int>,
 *     replicas: int, healthyReplicas: int, failures: list<string>}
 */
class SentinelInspector
{
    public function __construct(private readonly SentinelClientFactory $sentinels = new SentinelClientFactory) {}

    /**
     * Poll every configured sentinel and summarise what the fleet believes.
     *
     * @param  array|null  $config  A `database.redis.*` entry; defaults to the `default` connection.
     * @return FleetReport
     */
    public function inspect(?array $config = null): array
    {
        $config ??= (array) config('database.redis.default', []);

        $service = (string) ($config['sentinel_service'] ?? 'mymaster');
        $hosts = $this->sentinels->parseHosts($config['sentinel_hosts'] ?? '');

        $report = [
            'configured' => count($hosts),
            'answering' => 0,
            'quorum' => false,
            'masters' => [],
            'replicas' => 0,
            'healthyReplicas' => 0,
            'failures' => [],
        ];

        foreach ($hosts as [$host, $port]) {
            try {
                $this->pollSentinel($this->createSentinel($host, $port, $config), $service, $report);
            } catch (Throwable $exception) {
                $report['failures'][] = "{$host}:{$port} ({$exception->getMessage()})";
            }
        }

        return $report;
    }

    /**
     * Fold one sentinel's view into the running report.
     *
     * Replica counts are taken as the maximum any single sentinel reports rather than a sum: every sentinel
     * monitors the same pair, so adding them up would multiply one replica by the size of the fleet.
     *
     * @param  FleetReport  $report
     */
    private function pollSentinel(object $sentinel, string $service, array &$report): void
    {
        $address = $sentinel->getMasterAddrByName($service);

        $report['answering']++;

        if (is_array($address) && count($address) >= 2 && (string) $address[0] !== '') {
            $master = "{$address[0]}:{$address[1]}";
            $report['masters'][$master] = ($report['masters'][$master] ?? 0) + 1;
        }

        // CKQUORUM is the authoritative answer to "could this fleet actually run a failover right now" - it
        // accounts for both the configured quorum and the majority needed to elect a leader.
        try {
            $report['quorum'] = $sentinel->ckquorum($service) || $report['quorum'];
        } catch (RedisException) {
            // -NOQUORUM is reported as an error; leave the flag as whatever another sentinel said.
        }

        $replicas = $sentinel->slaves($service) ?: [];

        $report['replicas'] = max($report['replicas'], count($replicas));
        $report['healthyReplicas'] = max(
            $report['healthyReplicas'],
            count(array_filter($replicas, $this->replicaIsHealthy(...))),
        );
    }

    /**
     * Whether a `SENTINEL SLAVES` entry describes a replica that could actually be promoted.
     *
     * @param  array  $replica
     */
    private function replicaIsHealthy(array $replica): bool
    {
        $flags = (string) ($replica['flags'] ?? '');

        return !str_contains($flags, '_down')
            && !str_contains($flags, 'disconnected')
            && ($replica['master-link-status'] ?? null) === 'ok';
    }

    /**
     * Protected as the test seam, mirroring {@see PhpRedisSentinelConnector::createSentinel()}.
     */
    protected function createSentinel(string $host, int $port, array $config): object
    {
        return $this->sentinels->make($host, $port, $config);
    }
}
