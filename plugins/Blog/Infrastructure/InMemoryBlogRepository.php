<?php

declare(strict_types=1);

namespace Zef\Plugin\Blog\Infrastructure;

/**
 * Development/test persistence adapter. Production adapters must use the
 * framework QueryBuilder/SqlQuery contracts, whose values are parameterized.
 * This adapter is intentionally isolated behind BlogService.
 */
final class InMemoryBlogRepository
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $records = [];

    /** @var array<string,list<array<string,mixed>>> */
    private array $revisions = [];

    public function find(string $type, string $id): ?array { return $this->records[$type][$id] ?? null; }
    public function save(string $type, array $record): void { $this->records[$type][$record['id']] = $record; }
    public function delete(string $type, string $id): ?array { $old = $this->find($type, $id); unset($this->records[$type][$id]); return $old; }
    /** @return list<array<string,mixed>> */
    public function all(string $type): array { return array_values($this->records[$type] ?? []); }
    public function addRevision(string $postId, array $revision): void { $this->revisions[$postId][] = $revision; }
    /** @return list<array<string,mixed>> */
    public function revisions(string $postId): array { return $this->revisions[$postId] ?? []; }
}
