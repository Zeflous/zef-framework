# Transaction lifecycle hooks

> Added in v2.22.1 (issue #65, item 1) — documentation of hook error
> semantics for the `TransactionManagerInterface::afterCommit()` lifecycle.

The framework's transaction orchestration layer
(`src/Domain/Database/TransactionManagerInterface.php`,
`src/Application/Database/TransactionManager.php`) was introduced in
v2.22.0 alongside the Unit-of-Work-lite (`UnitOfWork`). It exposes one
lifecycle hook: `afterCommit(callable): void`.

This document describes **what happens when a hook throws**, the
contracts hooks must honour, and how to reason about failure
propagation at each phase of the transaction lifecycle.

---

## The hook lifecycle

```
caller
  └─ TransactionManager::withTransaction($fn)
       ├─ $fn($conn) …………………… (1) handler body — DB writes happen here
       ├─ (UnitOfWork::flush($conn)) … (2) deferred writes run FIFO
       ├─ connection->commit() …………… (3) atomic commit — point of no return
       └─ drainHooks() …………………… (4) afterCommit hooks run FIFO
```

The hook queue accumulates across the whole nested managed-scope tree
(inner scopes become savepoints of the outermost scope). Hooks are
**discarded on rollback** for the failed scope and all its descendants.
If the outer scope catches an inner failure and continues, hooks queued
before the failed scope remain pending, and later hooks can still be
registered. Surviving hooks execute in FIFO order only after the
**outermost** commit succeeds. An outermost rollback discards every hook.

---

## Hook error semantics

> "Hook" in this section means a closure registered via
> `TransactionManagerInterface::afterCommit()`.

### Phase (1) — handler body

A throw here is caught by `withTransaction()`; the connection rolls
back via the underlying primitive, the failed scope's hooks are discarded, and
the throwable is re-thrown to the caller. **Nothing has been
committed**, so no afterCommit hook has run. This is the normal
failure story; callers should treat it as "the command never
happened".

### Phase (2) — UnitOfWork flush

A throw here rolls back the transaction (same path as phase 1). The
failed scope's hooks are discarded. Hooks do **not** run for that scope.
With `UnitOfWork::flush()`, a caller retrying the command must re-record
fresh operations because the queue is cleared before execution. The
opt-in `flushRetrying()` method retains its operation snapshot across
attempts and clears the queue only on success (see "UoW retry strategy").

### Phase (3) — atomic commit

This is the **point of no return**. If the commit itself fails (e.g.
the connection died, the DB rejected a constraint at the wire
protocol level), the underlying primitive throws, the hook queue is
discarded, and the throwable propagates. Hooks do not run because the
data was not committed.

### Phase (4) — `afterCommit` hook execution

**Hooks run AFTER the commit has been applied.** A hook that throws
propagates **after the data is already committed**. The caller
observes the throwable, but the writes cannot be rolled back by the
framework — they are already in the database. This mirrors the CQRS
event fan-out contract: listeners that fail after commit are real
failures, not transactional ones.

**Decision rule (documented):**
- A failing hook **must not** be re-tried by the framework. The
  committed data is visible; running the hook twice may produce
  duplicate side effects (e.g. two notification emails, two cache
  invalidations).
- Callers catching a hook failure should log it, surface it via
  observability, and decide whether the command result is still
  valid. The default answer is "yes" — the command's primary intent
  (the DB write) succeeded; the side-effect fan-out is best-effort.

---

## Contracts hooks MUST honour

1. **Idempotency.** A hook may run more than once under retry
   scenarios at the caller level (never within one
   `withTransaction()` scope — see below). Hooks must be safe to
   re-execute. Typical patterns:
   - Event listeners that publish to a queue with a deterministic
     message id (dedup at the consumer).
   - Cache invalidations that target a stable key.
   - Idempotent HTTP callbacks (PUT, not POST-create).

2. **No DB writes on the same connection during the commit phase.**
   The connection's transaction is already committed when hooks
   fire; issuing writes from a hook means **auto-commit mode**
   (one statement = one implicit transaction) — those writes are
   invisible to the command's atomic contract and cannot be rolled
   back if a later hook throws. If a hook needs to persist
   bookkeeping (e.g. an outbox table), use a **separate connection**
   or enqueue a job for asynchronous processing.

3. **Be fast.** Hooks execute on the same thread that ran the
   command; they hold whatever resources (DB connection from the
   pool, locks) the caller holds. See "Hook timeout" below for the
   v2.22.1 opt-in guard.

4. **Capture state in the closure, not by reference mutation.**
   Hooks are scheduled FIFO but may execute in immediate mode when
   registered outside a managed scope (`afterCommit()` runs the hook
   synchronously if no transaction is open). Closures that mutate
   external state by reference create ordering hazards — capture
   values, return results, and let the caller decide.

5. **Never rely on `inTransaction()` returning true inside a hook.**
   During `drainHooks()` the manager's scope depth is already 0 —
   the transaction is committed. A hook that calls `withTransaction()`
   starts a **new** managed scope (correct, intended).

---

## `@throws` contracts

The framework declares the following throwable contracts on the
transaction orchestration surface:

### `TransactionManagerInterface::withTransaction()`

```
@throws \Throwable  The inner $fn threw, OR the underlying commit
                   primitive failed. Hooks registered since this
                   scope began are discarded. Rollback mechanics
                   are delegated to the connection primitive.
```

Hooks do **not** throw out of `withTransaction()` — they run only
after a successful commit, and their failures propagate via the
`drainHooks()` call site (see below).

### `TransactionManagerInterface::afterCommit()`

```
@throws \Throwable  When invoked OUTSIDE a managed scope, the hook
                   runs immediately and any throw propagates.
                   When invoked INSIDE a managed scope, the hook is
                   queued and may throw later — during drainHooks(),
                   AFTER the commit has been applied.
```

### `TransactionManager::drainHooks()` (private)

```
@throws \Throwable  A queued hook threw. The throw propagates to
                   withTransaction()'s caller AFTER the commit has
                   succeeded. The remaining hooks in the queue are
                   NOT executed (the queue was already cleared
                   before draining started).
```

The `try { … } finally { $this->flushing = false; }` in
`drainHooks()` resets the immediate-mode flag so subsequent
`afterCommit()` calls behave correctly, but the remaining hooks
are abandoned — re-running them would double-execute the ones
that already succeeded.

---

## Hook timeout (v2.22.1, item 3 of issue #65)

`TransactionManager` accepts an optional `?int $hookDurationThresholdMs`
constructor argument. When set, each hook is timed; if it exceeds the
threshold, a debug-level log entry is emitted via the optional
`Psr\Log\LoggerInterface` (default `NullLogger`, i.e. silent —
preserving v2.22.0 behaviour).

This is **cooperative**, not a hard timeout — PHP cannot safely
interrupt a running closure. The guard surfaces misbehaving
listeners in observability without changing the failure semantics
above.

Recommended threshold: **50 ms** for request-scoped handlers,
**500 ms** for job/worker scopes.

---

## UoW retry strategy (v2.22.1, item 2 of issue #65)

The `TransactionalCommandBus` accepts an optional
`UnitOfWorkRetryPolicy` value object. When configured, the **flush
phase** (phase 2 above) is wrapped in a retry loop:

- Only the flush is retried — **never** the command handler body.
  Side effects produced during dispatch (event publishes, log writes)
  cannot be re-run safely; the contract is "retry the DB writes only".
- The retryable class list is configurable; defaults follow the
  transient-failure taxonomy (deadlock, lock-wait-timeout,
  serialization-failure).
- `UnitOfWork::flushRetrying()` replays the same operation snapshot in
  FIFO order on each attempt. Its queue is cleared only on success;
  an escaping failure leaves it populated. This differs from `flush()`,
  which clears its queue before executing any operations.
- The policy is **off by default** (`null` = current v2.22.0
  behaviour preserved).

See `src/Domain/Database/UnitOfWorkRetryPolicy.php` for the value
object and `tests/Unit/CqrsTransactionalBusRetryTest.php` for
behavioural coverage.

---

## Further reading

- `src/Domain/Database/TransactionManagerInterface.php` — the
  outbound port contract.
- `src/Application/Database/TransactionManager.php` — the default
  implementation.
- `src/Application/Database/UnitOfWork.php` — the deferred-write
  queue.
- `src/Application/CQRS/TransactionalCommandBus.php` — the
  decorator that wires UoW + transaction together.
- `docs/CHANGELOG-v2.22.0.md` — the original v2.22.0 release notes.
- `docs/CHANGELOG-v2.22.1.md` — the v2.22.1 hardening pass (this
  issue).

## CQRS idempotency and transaction ownership

With the built-in `CommandBus`, `TransactionalCommandBus` places the handler,
UoW flush, and commit inside the idempotency store's `remember()` producer.
A handler, flush, or commit failure does not publish a result. Retrying the
same key runs the command again. A successful replay skips the transaction,
handler, flush, and event fan-out. There is no separate lookup/write or
rollback-time invalidation. The in-memory store reserves the key throughout
`remember()` and rejects same-key re-entry (including another Fiber) with
`LogicException`; its reservation is released on both success and failure.
Custom stores must likewise serialize or reject concurrent producers for a key.

Command events run after commit and after the result is cached, even when only
the decorator receives the transaction manager. A listener or after-commit
hook failure still propagates, but the committed result stays cached so a
retry cannot duplicate the database write. As with ordinary idempotency,
cache backend write failures and process crashes after the database commit
require a durable, transaction-coordinated store for exactly-once guarantees.

An uncached command with an idempotency key and store must own the outermost
transaction. Dispatching it inside an existing managed or raw connection
transaction throws `LogicException` before its handler runs: releasing a
savepoint does not confirm the outer commit, and the current remember-only
store port cannot reserve a result until that enclosing scope completes.
Nested commands without idempotency and replays of already committed keys
remain supported. A standalone `CommandBus` wired with a transaction manager
also rejects uncached keyed dispatches inside an existing managed scope.
Custom `CommandBusInterface` implementations wrapped by the decorator retain
responsibility for their own idempotency policy.
