# Redis topologies

One switch — `REDIS_TOPOLOGY=standalone|sentinel|cluster` (default `standalone`) — reshapes
`config/database.php`; a typo fails loudly at boot/`config:cache`. Whatever the topology, the
application surface is constant: four connections (`default`, `cache`, `sessions`, `queue`), and
`SESSION_CONNECTION=sessions`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, Horizon and
`auth:flush-sessions` all work unchanged.

| | standalone | sentinel | cluster |
| --- | --- | --- | --- |
| Serves | one Redis server | HA master/replica pair with automatic failover | horizontal sharding |
| Session isolation | DB index 2 | DB index 2 (identical) | dedicated key prefix (`REDIS_SESSIONS_PREFIX`) |
| Queue name | `default` | `default` | `{default}` — hash-tagged so the queue's keys share a slot |
| Client driver | `phpredis` | app-owned `phpredis-sentinel` | `phpredis` |
| `auth:flush-sessions` | FLUSHDB on db 2 | FLUSHDB on db 2 | per-master prefix sweep |

The extension requirement is real in every topology (`composer.json` declares `ext-redis`);
sentinel additionally needs phpredis ≥ 6.0 (the `RedisSentinel` options-array constructor).

## Sentinel: standalone semantics with discovery bolted on

Switching an environment to sentinel is exactly this — nothing else changes, and reverting is
deleting the lines:

```
REDIS_TOPOLOGY=sentinel
REDIS_SENTINEL_HOSTS=10.0.0.1:26379,10.0.0.2:26379,10.0.0.3:26379
```

`REDIS_HOST`/`REDIS_PORT` become irrelevant placeholders: the current master is discovered through
the sentinels by `App\Support\Redis\PhpRedisSentinelConnector` (registered in
`AppServiceProvider`), because phpredis has no Sentinel support of its own and Laravel's stock
connector pins the first host it saw. `REDIS_PASSWORD`/`REDIS_USERNAME` still authenticate the
data nodes; `REDIS_SENTINEL_USERNAME`/`_PASSWORD` authenticate the sentinels (Redis 6.2+ ACLs).

### Reliability model

- **Discovery** runs through a per-process address cache (the four connections of one request pay
  one sentinel round-trip); unreachable sentinels are skipped, and when none answers the error
  names every host tried. The cache is per *process*, which means per request under php-fpm —
  statics do not survive between requests there. It only spans a failover in a long-lived process
  (Horizon, queue workers, Octane), where a stale entry costs one forced rediscovery.
- **Failover is retried on the same budget whether the connection is open or being opened**
  (`App\Support\Redis\SentinelRetryPolicy`). A failover-class error — READONLY from a demoted
  master, connection refused from a dead one, LOADING from a just-promoted replica, a discovery
  failure while sentinels are still answering — logs a warning, rebuilds the client with fresh
  discovery, waits `REDIS_SENTINEL_RETRY_DELAY`, and re-runs. Covering *connect* is the half that
  matters most for the web tier: under fpm every request builds all four connections from scratch,
  so a request arriving mid-election would otherwise never reach the in-command loop at all.
  The log signature of a healthy failover is a handful of `retryable failure (attempt 1/3)`
  warnings and nothing else.
- **Rediscovery verifies `role:master`.** Sentinels flip their view before the old master finishes
  demoting, and a replica answers reads perfectly happily — without the check the mistake would
  stay invisible until the next write.
- **Bounded socket timeouts are load-bearing** (`REDIS_TIMEOUT`/`REDIS_READ_TIMEOUT`, 2.0s
  defaults, applied by the sentinel branch): phpredis defaults to infinite, and a dying master
  that accepts the TCP handshake but never answers would hang requests to `max_execution_time`
  instead of entering the retry path. Never set them to 0 — the same rule every sentinel-capable
  client enforces (GUI clients included).
- **`REDIS_SENTINEL_RETRY_DEADLINE` is the bound that actually protects a request.** Attempts ×
  delay is not the budget: an attempt against a dead master also pays a connect timeout, a read
  timeout, phpredis' own socket retries and a discovery sweep across every sentinel, so counting
  attempts alone understates the worst case by an order of magnitude. The deadline caps the wall
  clock for one **recovery** (5s default, checked before each retry so a doomed attempt is never
  started) — keep it comfortably under your fpm and load-balancer probe timeouts. `max_retries`
  also drops to 1 under sentinel, because phpredis retrying a dead socket first only multiplies
  every attempt before the app-level budget ever sees the failure.
- **`(p)subscribe` is exempt from both bounds, and has to be.** A subscription blocks by design, so
  the read timeout is lifted for its duration (an idle subscriber is not a failure) *and* the retry
  budget stops being scoped to the call. Applied literally, both would misfire: a subscription older
  than the deadline would get zero retries on its first failover, and the attempt counter — which
  spans the whole process for a subscriber — would let it survive exactly `retry_attempts` failovers
  in its lifetime and then die on the next one. Instead the budget resets whenever a subscription
  lasted long enough to have been doing work, since that proves the previous incident is over. One
  that never establishes still gives up after `retry_attempts`.

### What each failure state costs

Verified live by the drill (below); this is the designed behavior, not aspiration:

| State | Impact |
| --- | --- |
| Controlled failover (`SENTINEL FAILOVER`) | zero errors; one reconnect warning per connection |
| Hard master death, held connections | zero errors; heal on attempt 1 once promotion lands *inside the deadline* |
| Promotion outlasting the deadline | `SentinelFailoverException` — observed as `gave up after 1 retry and 4688ms`, i.e. two ~2s socket timeouts against the 5s bound, so a hard death rarely fits inside the default budget. A web request 500s and the next one succeeds. Horizon logs it and **keeps running** (five over 42 minutes in one drill session, process alive throughout); the job in flight falls under the at-least-once semantics below. |
| Hard master death, *new* connections during the election | retried on the same budget as a command; a promotion landing inside `REDIS_SENTINEL_RETRY_DEADLINE` is invisible, one clean error past it |
| Election outlasting the deadline | bounded failure at the deadline, not at `max_execution_time`; the next request heals |
| Some sentinels down | discovery skips them; no impact on serving, and the `sentinel` health probe turns red |
| All sentinels down, warm processes | keep serving off cached discovery — sentinel loss is not Redis loss |
| All sentinels down, cold processes | fail fast (no retry: an unreachable fleet does not become reachable by waiting), error names every sentinel tried |
| Master with no good replica, `min-replicas-to-write` set | writes fail immediately with `NOREPLICAS` — deliberately not retried, see below |

## Queue delivery semantics under failover

Redis queues are **at-least-once** — no client can make them exactly-once, and failovers are when
the difference materializes. Three flavors, each observed under load:

1. **Duplicates**: a job's reserve (pop) is acknowledged by the old master but not yet replicated
   when the replica is promoted → the job is still pending on the new master → runs again,
   immediately. Design queued work to be idempotent; `ShouldBeUnique`/`WithoutOverlapping`/cache
   locks where duplicates genuinely matter.
2. **Lost acknowledged writes**: a write accepted by a master in its demotion window is wiped when
   that node resyncs as a replica. `min-replicas-to-write 1` on the master narrows (not closes)
   the window — at a price worth knowing before you set it: the master then *refuses* writes
   (`NOREPLICAS`) for the whole time it has no good replica, which includes the resync after every
   promotion. The client does not paper over that. Retrying it would add the full retry deadline
   to every write during a plain replica outage while barely helping the failover case, since a
   freshly promoted master has no replica at all until the demoted node reattaches and finishes
   syncing — longer than any budget worth having. So `NOREPLICAS` fails fast and shows up on the
   `sentinel` health probe instead of being absorbed silently.
3. **Ghost failed-jobs**: the *completion* ack is lost → the orphaned reservation resurfaces after
   `retry_after` (90s) with its attempt already counted → workers (`tries=1`) park it in the
   failed list *without re-running it*. A "failed" record whose work demonstrably completed is
   safe to forget; raising `tries` trades parked ambiguity for automatic (idempotency-requiring)
   re-runs.

## Dev stack and the drill

`docker compose -f docker/compose.dev.yaml --profile sentinel up -d` runs a real HA pair — master
:6380, replica :6381, three sentinels :26379–26381 (quorum 2) — sharing one network namespace so
`127.0.0.1` announcements are valid from the Herd-served app; `horizon-sentinel` is the matching
worker (run exactly one Horizon per topology).

`php artisan redis:sentinel-drill` exercises every reachable state against that stack — sentinel
outages (partial and total), replica outage, controlled failover with a connection held open
across it, hard master death via `docker pause` with *cold* connections hammered across the whole
election — writing a monotonic counter at each transition and finally auditing that Redis holds the
complete acknowledged sequence (write-loss proof). The cold-connection probes are the php-fpm case:
they assert both that no attempt outlives the retry deadline and that one opened while the master
was still frozen heals across the promotion. The
stack is restored to canonical state afterwards (all containers up, 6380 master, replica synced),
including repair of the circular replica deadlock chaotic restarts can produce. Preconditions and
`--keep-state` are documented in the command; after a manual failover drill the roles simply stay
swapped — Sentinel behavior, not a bug.

## Environment reference

| Env | Default | Meaning |
| --- | --- | --- |
| `REDIS_TOPOLOGY` | `standalone` | `standalone` \| `sentinel` \| `cluster`; anything else refuses to boot. |
| `REDIS_CLIENT` | `phpredis` | standalone/cluster only — sentinel always uses the app driver. |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | Server (standalone) or seed node (cluster); ignored under sentinel. |
| `REDIS_DB` / `REDIS_CACHE_DB` / `REDIS_SESSION_DB` / `REDIS_QUEUE_DB` | `0/1/2/3` | Per-connection DB indexes (standalone + sentinel). |
| `REDIS_SENTINEL_HOSTS` | `127.0.0.1:26379` | Comma-separated sentinels; port defaults to 26379. |
| `REDIS_SENTINEL_SERVICE` | `mymaster` | The monitored master-set name. |
| `REDIS_SENTINEL_USERNAME` / `_PASSWORD` | *(unset)* | Sentinel ACL credentials (not the data nodes'). |
| `REDIS_SENTINEL_TIMEOUT` | `0.5` | Per-sentinel discovery probe timeout, seconds. |
| `REDIS_SENTINEL_RETRY_ATTEMPTS` / `_RETRY_DELAY` | `3` / `500` | Failover retry budget (count × ms), applied to connecting as well as to commands. |
| `REDIS_SENTINEL_RETRY_DEADLINE` | `5000` | Wall-clock cap in ms for one recovery including its connect, read and discovery costs; 0 disables. The bound that keeps a failover off `max_execution_time`. Not applied to `(p)subscribe`. |
| `REDIS_TIMEOUT` / `REDIS_READ_TIMEOUT` | `2.0` / `2.0` | Data-node socket timeouts (sentinel branch); never 0. Lifted for the duration of a `(p)subscribe`. |
| `REDIS_PERSISTENT` | `false` | Prefer false with sentinel: pooled sockets can pin a demoted master until reuse fails. |
| `REDIS_MAX_RETRIES`, `REDIS_BACKOFF_*` | `1` under sentinel (`3` otherwise), decorrelated jitter | phpredis same-socket retries — blips, not failover; the app budget owns failover. |
| `REDIS_SESSIONS_PREFIX` | app-derived | Cluster only: the sessions key-prefix isolation. |
| `REDIS_QUEUE` | topology-dependent | Queue name; carries the `{hash tag}` on cluster only. |
