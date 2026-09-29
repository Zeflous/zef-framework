<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Application layer: in-process orchestration)
 * Extracted from InMemoryOutbox so the store itself stays under its size
 * budget: the mutable insertion-ordered entry storage lives here.
 */

namespace Zef\Framework\EventSourcing;

/**
 * Insertion-ordered storage of {@see OutboxEntry} values, keyed by entry id,
 * with FIFO-sorted (createdAt, id) predicate queries.
 *
 * Shared by the store and claim surfaces of {@see InMemoryOutbox}; purely
 * internal to the in-memory outbox implementation.
 */
final class OutboxEntryBook
{
    /** @var array<string, OutboxEntry> keyed by entry id, insertion-ordered */
    private array $entries = [];

    public function add(OutboxEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    public function replace(OutboxEntry $entry): void
    {
        $this->entries[$entry->id] = $entry;
    }

    public function find(string $id): ?OutboxEntry
    {
        return $this->entries[$id] ?? null;
    }

    /** Total number of entries in every state. */
    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * Entries matching $predicate, in FIFO order (createdAt, id) ascending.
     *
     * @param \Closure(OutboxEntry): bool $predicate
     *
     * @return list<OutboxEntry>
     */
    public function matching(\Closure $predicate): array
    {
        $matched = [];
        foreach ($this->entries as $entry) {
            if ($predicate($entry)) {
                $matched[] = $entry;
            }
        }
        usort($matched, self::compareFifo(...));

        return $matched;
    }

    /**
     * Number of entries matching $predicate (no ordering work).
     *
     * @param \Closure(OutboxEntry): bool $predicate
     */
    public function countMatching(\Closure $predicate): int
    {
        $count = 0;
        foreach ($this->entries as $entry) {
            if ($predicate($entry)) {
                ++$count;
            }
        }

        return $count;
    }

    /** FIFO order for listings: (createdAt, id) ascending. */
    private static function compareFifo(OutboxEntry $a, OutboxEntry $b): int
    {
        return [$a->createdAtUnixNano, $a->id] <=> [$b->createdAtUnixNano, $b->id];
    }
}
