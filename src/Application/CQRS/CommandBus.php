<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\TransactionManagerInterface;
use Zef\Framework\Event\EventBusInterface;

final class CommandBus implements CommandBusInterface
{
    use CqrsBusTrait;

    public function __construct(
        private readonly ?IdempotencyStoreInterface $idempotencyStore = null,
        private readonly int $idempotencyTtlSeconds = 3600,
        private readonly ?EventBusInterface $eventBus = null,
        private readonly ?TransactionManagerInterface $transactions = null,
    ) {
        if ($idempotencyTtlSeconds < 1) {
            throw new \InvalidArgumentException('CQRS idempotency TTL must be positive.');
        }
    }

    #[\Override]
    public function register(string $commandClass, callable|CommandHandlerInterface $handler): void
    {
        $this->assertMutable();
        $this->validateMessageClass($commandClass, 'command');
        if (isset($this->handlers[$commandClass])) {
            throw new CqrsHandlerConflictException("Command handler already registered for '{$commandClass}'.");
        }
        $this->handlers[$commandClass] = $handler instanceof CommandHandlerInterface
            ? $handler(...)
            : $handler;
    }

    #[\Override]
    public function use(CqrsMiddlewareInterface $middleware): void
    {
        $this->assertMutable();
        $this->middleware[] = $middleware;
    }

    #[\Override]
    public function dispatch(object $command, ?CqrsContext $context = null): mixed
    {
        return $this->dispatchCommand($command, $context);
    }

    /**
     * Keep the complete transaction inside the idempotency producer.
     *
     * @param null|\Closure(ConnectionInterface): void $beforeCommit
     *
     * @internal used by TransactionalCommandBus
     */
    public function dispatchTransactionally(
        object $command,
        ?CqrsContext $context,
        TransactionManagerInterface $transactions,
        ?\Closure $beforeCommit = null,
        ?IsolationLevel $isolation = null,
    ): mixed {
        return $this->dispatchCommand($command, $context, $transactions, $beforeCommit, $isolation);
    }

    #[\Override]
    public function freeze(): void
    {
        $this->frozen = true;
    }

    #[\Override]
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @param null|\Closure(ConnectionInterface): void $beforeCommit */
    private function dispatchCommand(
        object $command,
        ?CqrsContext $context,
        ?TransactionManagerInterface $transactions = null,
        ?\Closure $beforeCommit = null,
        ?IsolationLevel $isolation = null,
    ): mixed {
        $context ??= CqrsContext::create();
        $pendingEvents = [];
        $postCommitFailure = null;
        $idempotent = $context->idempotencyKey !== null && $this->idempotencyStore instanceof IdempotencyStoreInterface;
        $eventTransactions = $transactions ?? $this->transactions;
        $execute = function () use ($command, $context, &$pendingEvents): mixed {
            $handler = $this->resolveHandler($command, 'command');
            $next = $this->buildChain($handler);
            $result = $next($command, $context);
            if ($result instanceof CqrsEventResult) {
                // Defer the event fan-out until AFTER the result is cached:
                // the handler already ran, so a listener failure must not
                // invalidate idempotency — with the fan-out inside the
                // producer, EventDispatchException skipped the cache write
                // and a client retry re-EXECUTED the command (double side
                // effects).
                $pendingEvents = $result->events;

                return $result->result;
            }

            return $result;
        };
        $produce = function () use ($execute, $transactions, $eventTransactions, $beforeCommit, $isolation, $idempotent, &$postCommitFailure): mixed {
            // A savepoint is not a durable commit. The remember-only store
            // port cannot reserve a key until an enclosing scope completes.
            if ($idempotent && ($eventTransactions?->inTransaction() ?? false)) {
                throw new \LogicException('Idempotent commands must own the outermost managed transaction.');
            }
            if (!$transactions instanceof TransactionManagerInterface) {
                return $execute();
            }

            $committed = false;
            $result = null;

            try {
                return $transactions->withTransaction(function (ConnectionInterface $connection) use ($transactions, $execute, $beforeCommit, $idempotent, &$committed, &$result): mixed {
                    if ($idempotent && $connection->transactionLevel() !== 1) {
                        throw new \LogicException('Idempotent commands must own the outermost connection transaction.');
                    }
                    // First hook distinguishes a commit failure from a later
                    // hook failure: committed writes must remain replayable.
                    $transactions->afterCommit(static function () use (&$committed): void {
                        $committed = true;
                    });
                    $result = $execute();
                    $beforeCommit?->__invoke($connection);

                    return $result;
                }, $isolation);
            } catch (\Throwable $error) {
                if (!$committed) {
                    throw $error;
                }
                $postCommitFailure = $error;

                return $result;
            }
        };
        $result = $this->guardDispatchDepth(
            function () use ($command, $context, $produce): mixed {
                if ($context->idempotencyKey !== null && $this->idempotencyStore instanceof IdempotencyStoreInterface) {
                    return $this->idempotencyStore->remember(
                        hash('sha256', $command::class . '|' . $context->idempotencyKey),
                        $produce,
                        $this->idempotencyTtlSeconds,
                    );
                }

                return $produce();
            },
        );
        if ($postCommitFailure instanceof \Throwable) {
            throw $postCommitFailure;
        }
        // Fan out only for THIS invocation: $pendingEvents stays empty on
        // an idempotent replay, so events are never re-fired. The first
        // caller observes EventDispatchException; all listeners already
        // ran by then (aggregating dispatcher).
        //
        // With a TransactionManager wired (v2.22.0), fan-out is queued via
        // afterCommit(): events fire only after the OUTERMOST transaction
        // commits — a rolled-back command emits nothing, and listeners
        // observe committed data. Without one (or outside a managed
        // scope), afterCommit executes immediately, preserving the exact
        // pre-2.22 timing.
        foreach ($pendingEvents as $event) {
            $fanOut = function () use ($event, $context): void {
                $this->eventBus?->dispatchWithContext($event, $context->toEventContext());
            };
            if ($eventTransactions instanceof TransactionManagerInterface) {
                $eventTransactions->afterCommit($fanOut);

                continue;
            }
            $fanOut();
        }

        return $result;
    }
}
