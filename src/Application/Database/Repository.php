<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: services over the port)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Thin table-data-gateway base class composed on {@see QueryBuilder}.
 *
 * Deliberately NOT an ORM: no identity map, no lazy relations, no change
 * tracking — just typed convenience over the query builder so repositories
 * stay in the application layer while the domain stays persistence-agnostic.
 */
abstract class Repository
{
    private readonly string $table;

    public function __construct(
        protected readonly ConnectionInterface $connection,
        string $table,
    ) {
        // Validate eagerly so a typo surfaces at construction, not first query.
        self::assertValidTable($table);
        $this->table = $table;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        return $this->connection->execute($this->qb()->insert($row)->build());
    }

    /**
     * @return null|array<string, mixed>
     */
    public function find(int|string $id, string $primaryKey = 'id'): ?array
    {
        return $this->connection->fetchOne(
            $this->qb()->where($primaryKey, '=', $id)->limit(1)->build(),
        );
    }

    /**
     * AND-equality lookup; null values become IS NULL.
     *
     * @param array<string, mixed> $criteria
     *
     * @return null|array<string, mixed>
     */
    public function findOneBy(array $criteria): ?array
    {
        return $this->connection->fetchOne(
            $this->applyCriteria($this->qb(), $criteria)->limit(1)->build(),
        );
    }

    /**
     * @param array<string, mixed>       $criteria
     * @param null|array<string, string> $orderBy  column => ASC|DESC
     *
     * @return list<array<string, mixed>>
     */
    public function findBy(array $criteria = [], ?array $orderBy = null, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->applyCriteria($this->qb(), $criteria);
        foreach ($orderBy ?? [] as $column => $direction) {
            $qb->orderBy($column, $direction);
        }
        if ($limit !== null) {
            $qb->limit($limit);
            if ($offset > 0) {
                $qb->offset($offset);
            }
        } elseif ($offset !== 0) {
            throw new \InvalidArgumentException('offset() requires a limit.');
        }
        // No limit and no offset: nothing to apply to the builder.

        return $this->connection->fetchAll($qb->build());
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        $row = $this->connection->fetchOne($this->applyCriteria($this->qb(), $criteria)->count()->build());
        $value = $row['aggregate'] ?? null;
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new QueryException('count() returned no aggregate value.');
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function exists(array $criteria = []): bool
    {
        return $this->count($criteria) > 0;
    }

    /**
     * @param array<string, mixed> $pairs
     * @param array<string, mixed> $criteria
     */
    public function update(array $pairs, array $criteria): int
    {
        if ($criteria === []) {
            throw new \InvalidArgumentException('update() requires at least one criterion.');
        }

        return $this->connection->execute($this->applyCriteria($this->qb()->update($pairs), $criteria)->build());
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function delete(array $criteria): int
    {
        if ($criteria === []) {
            throw new \InvalidArgumentException('delete() requires at least one criterion.');
        }

        return $this->connection->execute($this->applyCriteria($this->qb()->delete(), $criteria)->build());
    }

    /**
     * Fresh builder bound to this repository's table.
     */
    protected function qb(): QueryBuilder
    {
        return QueryBuilder::table($this->table);
    }

    /**
     * Eager table-name validation delegated to the query builder's own
     * identifier grammar (throws {@see QueryException} on a bad name).
     */
    private static function assertValidTable(string $table): void
    {
        new QueryBuilder()->quoteIdentifier($table, 'table');
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function applyCriteria(QueryBuilder $qb, array $criteria): QueryBuilder
    {
        foreach ($criteria as $column => $value) {
            if ($value === null) {
                $qb->whereNull($column);
            } else {
                $qb->where($column, '=', $value);
            }
        }

        return $qb;
    }
}
