<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Outbound port for SQL connections.
 *
 * The port is deliberately small: execute/fetch prepared statements built
 * by {@see QueryBuilder} (or raw DDL via {@see SqlQuery::raw()}), plus a
 * nested-transaction primitive with explicit savepoint semantics.
 *
 * Implementations must map every driver error onto the DatabaseException
 * hierarchy and never leak driver-specific exception types.
 */
interface ConnectionInterface
{
    /**
     * Run a statement, returning the number of affected rows.
     *
     * @throws QueryException when preparation or execution fails
     */
    public function execute(SqlQuery $query): int;

    /**
     * Fetch every result row as a string-keyed map.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(SqlQuery $query): array;

    /**
     * Fetch the first result row, or null when the result is empty.
     *
     * @return null|array<string, mixed>
     */
    public function fetchOne(SqlQuery $query): ?array;

    /**
     * Last auto-generated id, or null when the driver/last statement
     * produced none (e.g. PostgreSQL without a sequence name).
     */
    public function lastInsertId(): ?string;

    /**
     * Begin a transaction. Calling it while already inside one opens a
     * SAVEPOINT (nesting); $isolation may only be requested at level 0.
     *
     * Isolation semantics (v2.18.0 audit clarification; ordering made
     * driver-aware by P-6, issue #172):
     * - the isolation level is applied ONCE, via
     *   `SET TRANSACTION ISOLATION LEVEL`, anchored to the outermost
     *   BEGIN — BEFORE it on MySQL (there the statement scopes to the
     *   session's NEXT transaction), immediately AFTER it on PostgreSQL
     *   (outside a transaction block the statement is a silent no-op
     *   there) — and governs the ENTIRE transaction scope
     *   (including every savepoint opened within it);
     * - requesting an isolation level while nested (level > 0) throws
     *   TransactionException — savepoints cannot change isolation on
     *   most RDBMS, so the adapter refuses loudly rather than silently
     *   ignoring the request;
     * - SQLite rejects isolation levels outright (ConnectionException).
     *
     * @throws TransactionException when nested and $isolation requested
     * @throws ConnectionException  on SQLite with $isolation requested
     */
    public function beginTransaction(?IsolationLevel $isolation = null): void;

    /**
     * Commit the current level — releases a savepoint when nested,
     * commits for real at the outermost level.
     */
    public function commit(): void;

    /**
     * Roll the current level back — restores a savepoint when nested,
     * rolls back for real at the outermost level.
     */
    public function rollBack(): void;

    /**
     * Current nesting depth: 0 = no transaction, 1 = real transaction,
     * 2+ = inside savepoints.
     */
    public function transactionLevel(): int;

    /**
     * Run $fn inside a transaction, rolling back on any throwable.
     * Calls may nest — inner calls transparently become savepoints.
     *
     * Isolation semantics (v2.18.0 audit clarification — read this
     * before relying on $isolation in nested calls):
     * - $isolation is honoured ONLY when this call opens the OUTERMOST
     *   transaction (transactionLevel() === 0 on entry);
     * - in a NESTED call the $isolation argument is SILENTLY DROPPED:
     *   the inner scope is a SAVEPOINT of the already-running outer
     *   transaction, which keeps the isolation level chosen at the
     *   outermost begin. Most RDBMS cannot change isolation mid-
     *   transaction, so attempting it per-savepoint is impossible —
     *   the framework chooses the predictable "ignore" behaviour here
     *   (as opposed to beginTransaction(), which throws when nested).
     *   Do NOT pass $isolation from library code that may run nested —
     *   it will not do what the caller expects;
     * - the effective level therefore comes from the OUTERMOST
     *   transaction() / beginTransaction() call on the connection.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T $fn
     *
     * @return T
     */
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed;
}
