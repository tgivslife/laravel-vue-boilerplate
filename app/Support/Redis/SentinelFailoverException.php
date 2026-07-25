<?php

namespace App\Support\Redis;

use RedisException;

/**
 * The failover retry budget was spent without the operation succeeding.
 *
 * A RedisException rather than a RuntimeException on purpose: the public entry points of
 * {@see PhpRedisSentinelConnection} inherit `@throws RedisException` from the framework, and every caller
 * that guards Redis work does so with `catch (RedisException)`.
 * A sentinel deployment must not quietly fall outside those guards just because the driver underneath is app-owned.
 *
 * The original failure is always attached as `previous` - this type says "we stopped trying", the previous says what was actually wrong.
 */
class SentinelFailoverException extends RedisException
{
}
