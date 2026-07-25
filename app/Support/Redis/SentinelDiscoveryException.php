<?php

namespace App\Support\Redis;

use RedisException;

/**
 * Master discovery failed: no configured sentinel could name a master right now.
 *
 * Whether that is worth retrying depends entirely on *why*, which is what `$anySentinelAnswered` records:
 *
 *  - no sentinel answered at all - the fleet is unreachable. Retrying changes nothing, so this fails fast and
 *    surfaces through the health probes with every host it tried named in the message.
 *  - a sentinel answered but knows no master - an election is in flight. That resolves on its own, well inside
 *    the retry budget, so {@see SentinelRetryPolicy} treats it exactly like any other failover-class error.
 *
 * A RedisException like everything else this driver throws at the application; see
 * {@see SentinelFailoverException} for why.
 */
class SentinelDiscoveryException extends RedisException
{
    /**
     * @param  string  $message  Names every sentinel tried and why each one did not answer.
     * @param  bool  $anySentinelAnswered  Whether at least one sentinel responded (an election, not an outage).
     */
    public function __construct(string $message, public readonly bool $anySentinelAnswered = false)
    {
        parent::__construct($message);
    }
}
