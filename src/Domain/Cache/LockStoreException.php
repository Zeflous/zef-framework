<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — Domain layer (ports, contracts, value objects).
 * Dedicated exception for LockStoreInterface adapters whose backing store
 * returns an unexpected out-of-contract result (php:S112: replaces the
 * generic RuntimeException previously thrown by RedisLockStore). Extends
 * RuntimeException so existing catch blocks keep working unchanged.
 */

namespace Zef\Framework\Cache;

final class LockStoreException extends \RuntimeException {}
