<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Optimistic concurrency violation on an event stream.
 *
 * Raised by {@see EventStoreInterface::appendToStream()} when the last
 * committed version of the stream does not match the caller's expectation —
 * including the insert-time backstop: a concurrent append that won one of
 * the store's unique constraints after the version guard passed surfaces
 * here too, with the driver error chained as the cause (issue #170).
 * The expected/actual pair is exposed so callers can decide to reload the
 * aggregate and retry instead of guessing what went wrong.
 */
final class ConcurrencyException extends EventSourcingException
{
    public function __construct(
        private readonly int $expectedVersion,
        private readonly int $actualVersion,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Concurrency conflict: expected stream version {$expectedVersion}, actual is {$actualVersion}.",
            0,
            $previous,
        );
    }

    public function expectedVersion(): int
    {
        return $this->expectedVersion;
    }

    public function actualVersion(): int
    {
        return $this->actualVersion;
    }
}
