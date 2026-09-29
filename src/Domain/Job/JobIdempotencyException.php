<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Domain layer (ports, contracts, value objects)
 * Ecosystem Ports: durable idempotency cache for job handlers.
 */

namespace Zef\Framework\Job;

/**
 * Corrupt durable idempotency state: a stored value exists but its JSON
 * document is unparsable, so the {@see PdoJobIdempotencyStore} refuses to
 * answer from it instead of silently degrading to a cache miss.
 */
class JobIdempotencyException extends \RuntimeException
{
    /**
     * Corrupt stored value: the row exists but its JSON document is
     * unparsable.
     */
    public static function corruptValue(\JsonException $previous): self
    {
        return new self('Stored idempotency value is not valid JSON.', 0, $previous);
    }
}
