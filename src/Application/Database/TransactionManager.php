<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: orchestration services)
 * Added in v2.22.0 (Transaction orchestration & UoW-lite).
 */

namespace Zef\Framework\Database;

use Psr\Log\LoggerInterface;

/**
 * Default {@see TransactionManagerInterface} implementation over a single
 * {@see ConnectionInterface}.
 *
 * The transaction mechanics themselves (BEGIN / SAVEPOINT nesting,
 * isolation, rollback-on-throw) are delegated to the connection primitive —
 * this class owns ONLY the managed-scope bookkeeping and the after-commit
 * hook lifecycle:
 *
 * - `$scopeDepth` counts open managed scopes (raw connection transactions
 *   are invisible to it);
 * - `$hooks` accumulates FIFO across the whole nested scope tree;
 * - each scope checkpoints the queue length and discards hooks added
 *   within that scope (including descendants) if it fails;
 * - after the OUTERMOST managed commit, `$flushing` turns immediate-mode
 *   ON while the queue drains, so hooks enqueued by hooks run inline
 *   instead of re-queueing; on outermost failure the queue is discarded.
 *
 * Instances are stateful and NOT shareable across concurrent dispatches
 * (PHP request scope) — wire one per bus/worker.
 *
 * v2.22.1 (issue #65, item 3) — cooperative hook timeout guard:
 * when `$hookDurationThresholdMs` is set, each hook is timed; a debug
 * log entry is emitted via `$logger` (default `NullLogger`) when the
 * threshold is exceeded. This is observability-only — PHP cannot safely
 * interrupt a running closure.
 *
 * Full hook error-semantics documentation lives in
 * docs/TRANSACTION-HOOKS.md.
 */
final class TransactionManager implements TransactionManagerInterface
{
    /** @var list<callable(): void> */
    private array $hooks = [];

    private int $scopeDepth = 0;

    private bool $flushing = false;

    /**
     * @param null|int $hookDurationThresholdMs soft threshold in milliseconds;
     *        null disables the slow-hook debug log (default behaviour)
     * @param null|LoggerInterface $logger where to emit the debug
     *        log; defaults to a silent NullLogger
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ?int $hookDurationThresholdMs = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @template T
     *
     * @param callable(ConnectionInterface): T $fn
     * @param null|IsolationLevel $isolation transaction isolation level for the scope
     *
     * @return T
     *
     * @throws \Throwable The inner $fn threw, OR the underlying commit
     *         primitive failed. Hooks added within this scope are
     *         discarded; rollback is delegated to the connection.
     *         (Hook failures do NOT propagate
     *         from here — they run only on a successful commit and throw
     *         out of {@see drainHooks()}.)
     */
    #[\Override]
    public function withTransaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        $outermost = $this->scopeDepth === 0;
        $hookCheckpoint = count($this->hooks);
        ++$this->scopeDepth;

        try {
            $result = $this->connection->transaction(static fn (ConnectionInterface $conn): mixed => $fn($conn), $isolation);
        } catch (\Throwable $e) {
            --$this->scopeDepth;
            $this->hooks = array_slice($this->hooks, 0, $hookCheckpoint);

            throw $e;
        }
        --$this->scopeDepth;

        if ($outermost) {
            $this->drainHooks();
        }

        return $result;
    }

    /**
     * Queue $hook to run after the current scope's OUTERMOST commit.
     *
     * Executes immediately when no managed scope is open (including
     * during hook flushing). The hook receives no arguments; capture
     * what it needs in the closure.
     *
     * @param callable(): void $hook
     *
     * @throws \Throwable When invoked OUTSIDE a managed scope the hook
     *         runs immediately and any throw propagates. When invoked
     *         INSIDE a managed scope the hook is queued and may throw
     *         later — during {@see drainHooks()}, AFTER the commit has
     *         been applied.
     */
    #[\Override]
    public function afterCommit(callable $hook): void
    {
        if ($this->flushing || !$this->inTransaction()) {
            $hook();

            return;
        }
        $this->hooks[] = $hook;
    }

    #[\Override]
    public function inTransaction(): bool
    {
        return $this->scopeDepth > 0;
    }

    #[\Override]
    public function level(): int
    {
        return $this->scopeDepth;
    }

    /**
     * Drain the hook queue in FIFO order after the outermost commit.
     * The queue is cleared BEFORE draining so a hook failure can never
     * replay already-executed hooks on a later scope; `$flushing` makes
     * hooks registered during the drain run inline.
     *
     * v2.22.1 (issue #65, item 3) — cooperative slow-hook guard: when
     * `$hookDurationThresholdMs` is set, each hook is timed and a debug
     * log entry is emitted if it exceeds the threshold. This is
     * observability-only; the hook is allowed to complete.
     *
     * @throws \Throwable A queued hook threw. The throw propagates to
     *         withTransaction()'s caller AFTER the commit has
     *         succeeded. The remaining hooks in the queue are NOT
     *         executed (the queue was cleared before draining started).
     */
    private function drainHooks(): void
    {
        $pending = $this->hooks;
        $this->hooks = [];
        if ($pending === []) {
            return;
        }
        $this->flushing = true;

        try {
            foreach ($pending as $hook) {
                if ($this->hookDurationThresholdMs === null) {
                    $hook();

                    continue;
                }

                $start = hrtime(true);
                $hook();
                $elapsedNs = hrtime(true) - $start;
                $elapsedMs = (int) ceil($elapsedNs / 1_000_000);
                if ($elapsedMs > $this->hookDurationThresholdMs) {
                    $this->logger?->debug(
                        'afterCommit hook exceeded slow-hook threshold',
                        [
                            'elapsed_ms' => $elapsedMs,
                            'threshold_ms' => $this->hookDurationThresholdMs,
                        ],
                    );
                }
            }
        } finally {
            $this->flushing = false;
        }
    }
}
