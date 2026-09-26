<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.22.0 (Transaction orchestration & UoW-lite).
 */

namespace Zef\Framework\Database;

/**
 * Outbound port for framework-level transaction ORCHESTRATION.
 *
 * v2.18.0 gave {@see ConnectionInterface} the raw transaction primitives
 * (nested BEGIN/SAVEPOINT, commit, rollback, isolation). v2.22.0 adds the
 * orchestration layer on top of them, with the two capabilities the raw
 * primitives deliberately do not provide:
 *
 * 1. AFTER-COMMIT HOOKS — {@see afterCommit()} queues work that must run
 *    only after the OUTERMOST transaction commits (event fan-out, cache
 *    invalidation, notifications). Hooks are discarded when the scope
 *    rolls back, so a failed command never emits its events. Outside a
 *    managed scope, hooks execute immediately: callers do not need to
 *    know whether a transaction is already open.
 *
 * 2. A MANAGED SCOPE — {@see withTransaction()} composes the connection's
 *    primitive with hook scheduling, so nested managed scopes collapse
 *    into one atomic commit at the outermost level.
 *
 * Semantics that implementations MUST honour:
 *
 * - Hook ordering is FIFO per scope; hooks registered by nested scopes
 *   flush together after the outermost commit, and a hook registered
 *   DURING hook flushing executes immediately (the queue is already
 *   being drained, so re-queueing would be ambiguous);
 * - When the outermost scope fails, every hook registered within it is
 *   discarded — never replayed on a retry;
 * - When a nested scope fails, its hooks (including hooks from its
 *   descendants) are discarded; previously queued outer and sibling
 *   hooks remain pending until the outermost commit;
 * - A hook that throws propagates AFTER the commit has been applied:
 *   the data is committed, the failure is real, the caller observes it
 *   (mirrors the CQRS event fan-out contract);
 * - Raw connection transactions (via {@see ConnectionInterface} directly)
 *   bypass hook scheduling by design: hooks follow MANAGED scopes only,
 *   so mixing the two layers stays predictable.
 */
interface TransactionManagerInterface
{
    /**
     * Run $fn inside a managed transaction, rolling back on any throwable.
     * Calls may nest — inner scopes become savepoints of the outermost
     * transaction; the isolation level is honoured only by the outermost
     * call (the connection primitive's own semantics).
     *
     * After the OUTERMOST scope commits, surviving hooks queued via
     * {@see afterCommit()} within the whole scope tree run in FIFO order.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T $fn
     * @param null|IsolationLevel              $isolation honoured only when
     *                                         this call opens the outermost
     *                                         transaction
     *
     * @return T
     */
    public function withTransaction(callable $fn, ?IsolationLevel $isolation = null): mixed;

    /**
     * Queue $hook to run after the current scope's OUTERMOST commit.
     *
     * Executes immediately when no managed scope is open (including
     * during hook flushing). The hook receives no arguments; capture
     * what it needs in the closure.
     *
     * @param callable(): void $hook
     */
    public function afterCommit(callable $hook): void;

    /**
     * True when a managed scope is open. Raw connection transactions do
     * not count — scheduling follows managed scopes only.
     */
    public function inTransaction(): bool;

    /**
     * Nesting depth of the current MANAGED scope: 0 = none open.
     * (The underlying connection-level savepoint depth is a different
     * number when raw primitives are mixed in — prefer this one for
     * managed-scope decisions.).
     */
    public function level(): int;
}
