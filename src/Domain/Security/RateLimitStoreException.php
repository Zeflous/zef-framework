<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Shared rate-limiting ports and their failure vocabulary.
 */

namespace Zef\Framework\Security;

/**
 * A shared rate-limit store answered with a payload that does not match the
 * documented shape — the bucket state cannot be reconstructed safely, so the
 * caller fails loudly instead of guessing a counter value.
 */
class RateLimitStoreException extends \RuntimeException
{
    /**
     * The store's increment result is not the expected [count, reset] pair.
     */
    public static function unexpectedIncrementResult(): self
    {
        return new self('Redis rate-limit store returned an unexpected result.');
    }
}
