<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\TransactionalCommandBus;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionManager;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventSubscriberInterface;

/**
 * v2.22.0 — TransactionalCommandBus: command handling inside a managed
 * transaction, UoW flush before commit, post-commit event fan-out.
 * Full integration on real SQLite in-memory.
 *
 * @internal
 */
final class CqrsTransactionalBusTest extends TestCase
{
    private ConnectionInterface $conn;

    private TransactionManager $tx;

    /** @var list<object> */
    private array $firedEvents;

    private EventBusInterface $eventBus;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
        $this->tx = new TransactionManager($this->conn);
        $this->firedEvents = [];
        $this->eventBus = $this->recordingBus($this->firedEvents);
    }

    public function testHandlerWritesAndUowFlushCommitAtomically(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx, $uow);
        $conn = $this->conn;
        $bus->register(\stdClass::class, new readonly class($conn, $uow) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
                private UnitOfWork $uow,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['uow-write']));
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['direct-write']));

                return 'ok';
            }
        });

        self::assertSame('ok', $bus->dispatch(new \stdClass()));
        // The handler records FIRST and writes directly SECOND, yet the
        // direct write lands first: UoW records are deferred until the
        // post-handler flush. This asserts the deferral contract.
        self::assertSame(['direct-write', 'uow-write'], $this->names(), 'UoW writes execute at flush, not at record time');
        self::assertSame(0, $uow->pending(), 'flush cleared the queue');
    }

    public function testHandlerFailureRollsBackWritesAndDiscardsEvents(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx, $uow);
        $conn = $this->conn;
        $bus->register(\stdClass::class, new readonly class($conn, $uow) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
                private UnitOfWork $uow,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): never
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['uow-write']));
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['direct-write']));

                throw new \RuntimeException('handler explodes');
            }
        });

        try {
            $bus->dispatch(new \stdClass());
            self::fail('Expected handler failure to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->names(), 'nothing survives the rollback');
        self::assertSame([], $this->firedEvents, 'a rolled-back command emits no events');
    }

    public function testEventsFireOnlyAfterOutermostCommit(): void
    {
        // The listener records the connection's transaction level at
        // fan-out time: 0 proves the commit already happened (the same
        // connection sees its own uncommitted writes, so visibility alone
        // would not prove ordering).
        $observedLevel = null;
        $conn = $this->conn;
        $bus = new TransactionalCommandBus(
            new CommandBus(
                eventBus: $this->recordingBus($this->firedEvents, static function () use (&$observedLevel, $conn): void {
                    $observedLevel = $conn->transactionLevel();
                }),
                transactions: $this->tx,
            ),
            $this->tx,
        );
        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): mixed => new CqrsEventResult('done', [new \stdClass()]));

        self::assertSame('done', $bus->dispatch(new \stdClass()));
        self::assertSame(0, $observedLevel, 'fan-out must run after the outermost commit');
        self::assertCount(1, $this->firedEvents);
    }

    public function testManagerWiredButNoScopeFansOutImmediately(): void
    {
        // Compatibility contract: CommandBus + TransactionManager wired,
        // dispatch NOT wrapped in a managed scope → fan-out happens during
        // dispatch, exactly like pre-2.22.
        $bus = new CommandBus(eventBus: $this->eventBus, transactions: $this->tx);
        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): mixed => new CqrsEventResult(null, [new \stdClass()]));

        $bus->dispatch(new \stdClass());

        self::assertCount(1, $this->firedEvents, 'no managed scope open → immediate fan-out');
    }

    public function testDelegationMethodsProxyToInnerBus(): void
    {
        $inner = new CommandBus();
        $bus = new TransactionalCommandBus($inner, $this->tx);

        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): string => 'h');
        $bus->use(new class implements CqrsMiddlewareInterface {
            #[\Override]
            public function process(object $message, CqrsContext $context, \Closure $next): mixed
            {
                $result = $next($message, $context);
                assert(is_string($result));

                return 'wrapped-' . $result;
            }
        });
        self::assertFalse($bus->isFrozen());
        $bus->freeze();
        self::assertTrue($bus->isFrozen());

        self::assertSame('wrapped-h', $bus->dispatch(new \stdClass()), 'middleware registered via the decorator must run');

        try {
            $bus->register(self::class, static fn (object $c, CqrsContext $ctx): null => null);
            self::fail('Frozen inner bus must reject registration.');
        } catch (\LogicException) {
        }
    }

    public function testNestedDecoratedDispatchBecomesSavepoint(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx);
        $conn = $this->conn;
        $bus->register(TxNestedOuter::class, new readonly class($conn, $bus) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
                private TransactionalCommandBus $bus,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['outer']));
                $this->bus->dispatch(new \stdClass());

                return 'outer-done';
            }
        });
        $bus->register(\stdClass::class, new readonly class($conn) implements CommandHandlerInterface {
            public function __construct(
                private ConnectionInterface $conn,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['inner']));

                return 'inner-done';
            }
        });

        self::assertSame('outer-done', $bus->dispatch(new TxNestedOuter()));
        self::assertSame(['outer', 'inner'], $this->names(), 'nested dispatch commits within the outermost scope');
    }

    public function testIsolationOverrideSurfacesSqliteRejection(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(), $this->tx, isolation: IsolationLevel::Serializable);
        $bus->register(\stdClass::class, static fn (object $c, CqrsContext $ctx): string => 'x');

        try {
            $bus->dispatch(new \stdClass());
            self::fail('SQLite must reject an isolation override.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('SQLite does not support isolation levels', $e->getMessage());
        }
    }

    public function testFailedCommitDoesNotCacheResult(): void
    {
        // Model a connection whose first commit fails and rolls back.
        // The real SQLite connection proves the writes do not survive.
        $connection = $this->createMock(ConnectionInterface::class);
        $attempts = 0;
        $connection->method('transaction')->willReturnCallback(function (callable $fn) use (&$attempts): mixed {
            return $this->conn->transaction(static function (ConnectionInterface $conn) use ($fn, &$attempts): mixed {
                $result = $fn($conn);
                if (++$attempts === 1) {
                    throw new \RuntimeException('commit failed');
                }

                return $result;
            });
        });
        $tx = new TransactionManager($connection);
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore(), eventBus: $this->eventBus), $tx);
        $calls = 0;
        $bus->register(\stdClass::class, function () use (&$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['committed']));

            return new CqrsEventResult('ok', [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'commit-retry');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected commit failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('commit failed', $error->getMessage());
        }
        self::assertSame([], $this->names());
        self::assertSame([], $this->firedEvents);
        self::assertSame(1, $calls);

        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(['committed'], $this->names());
        self::assertSame(2, $calls);
        self::assertCount(1, $this->firedEvents);
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertSame(2, $attempts, 'a cached replay does not open a transaction');
        self::assertCount(1, $this->firedEvents);
    }

    public function testFailedFlushDoesNotCacheNullResult(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx, $uow);
        $calls = 0;
        $failFlush = true;
        $bus->register(\stdClass::class, static function () use ($uow, &$calls, &$failFlush): null {
            ++$calls;
            $uow->record(static function (ConnectionInterface $conn) use (&$failFlush): void {
                $conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['flushed']));
                if ($failFlush) {
                    $failFlush = false;

                    throw new \RuntimeException('flush failed');
                }
            });

            return null;
        });
        $context = CqrsContext::create(idempotencyKey: 'flush-retry');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected flush failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('flush failed', $error->getMessage());
        }
        self::assertSame([], $this->names());
        self::assertNull($bus->dispatch(new \stdClass(), $context));
        self::assertSame(['flushed'], $this->names());
        self::assertSame(2, $calls);

        $uow->record(static function (): never {
            throw new \RuntimeException('a cached replay must not flush');
        });
        self::assertNull($bus->dispatch(new \stdClass(), $context));
        self::assertSame(2, $calls);
        self::assertSame(1, $uow->pending());
    }

    public function testPostCommitHookFailureKeepsCommittedResultCached(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, function () use (&$calls): string {
            ++$calls;
            $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['committed']));
            $this->tx->afterCommit(static function (): never {
                throw new \RuntimeException('hook failed');
            });

            return 'ok';
        });
        $context = CqrsContext::create(idempotencyKey: 'hook-failure');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected hook failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('hook failed', $error->getMessage());
        }
        self::assertSame(['committed'], $this->names());
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
    }

    public function testCommitHookCannotReenterTheSameKeyThroughAnotherBus(): void
    {
        $store = new InMemoryIdempotencyStore();
        $context = CqrsContext::create(idempotencyKey: 'reentrant-key');
        $other = new TransactionalCommandBus(new CommandBus($store), $this->tx);
        $other->register(\stdClass::class, static function (): never {
            self::fail('An in-flight key must not execute a second handler.');
        });
        $bus = new TransactionalCommandBus(new CommandBus($store), $this->tx);
        $bus->register(\stdClass::class, function () use ($other, $context): string {
            $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['committed']));
            $this->tx->afterCommit(static function () use ($other, $context): void {
                $other->dispatch(new \stdClass(), $context);
            });

            return 'ok';
        });

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected the in-flight key to reject re-entry.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('already in progress', $error->getMessage());
        }
        self::assertSame(['committed'], $this->names());
        self::assertSame('ok', $other->dispatch(new \stdClass(), $context));
    }

    public function testEventFailureKeepsCommittedResultCached(): void
    {
        $observedLevel = null;
        $events = $this->recordingBus($this->firedEvents, function () use (&$observedLevel): never {
            $observedLevel = $this->conn->transactionLevel();

            throw new \RuntimeException('listener failed');
        });
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore(), eventBus: $events), $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, function () use (&$calls): CqrsEventResult {
            ++$calls;
            $this->conn->execute(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['committed']));

            return new CqrsEventResult('ok', [new \stdClass()]);
        });
        $context = CqrsContext::create(idempotencyKey: 'listener-failure');

        try {
            $bus->dispatch(new \stdClass(), $context);
            self::fail('Expected listener failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('listener failed', $error->getMessage());
        }
        self::assertSame(0, $observedLevel);
        self::assertSame(['committed'], $this->names());
        self::assertSame('ok', $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $calls);
    }

    public function testUncachedIdempotentCommandRejectsEnclosingManagedTransaction(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $calls = 0;
        $bus->register(\stdClass::class, static function () use (&$calls): int {
            return ++$calls;
        });
        $context = CqrsContext::create(idempotencyKey: 'nested-key');

        try {
            $this->tx->withTransaction(static fn (): mixed => $bus->dispatch(new \stdClass(), $context));
            self::fail('A savepoint cannot publish an idempotent result.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('outermost managed transaction', $error->getMessage());
        }
        self::assertSame(0, $calls);
        self::assertSame(1, $bus->dispatch(new \stdClass(), $context));
        self::assertSame(1, $this->tx->withTransaction(static fn (): mixed => $bus->dispatch(new \stdClass(), $context)));
        self::assertSame(1, $calls, 'an already committed result is safe to replay inside another transaction');
    }

    public function testUncachedIdempotentCommandRejectsEnclosingRawTransaction(): void
    {
        $bus = new TransactionalCommandBus(new CommandBus(new InMemoryIdempotencyStore()), $this->tx);
        $bus->register(\stdClass::class, static function (): never {
            self::fail('The handler must not run inside an enclosing raw transaction.');
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('outermost connection transaction');
        $this->conn->transaction(static fn (): mixed => $bus->dispatch(new \stdClass(), CqrsContext::create(idempotencyKey: 'raw-transaction')));
    }

    /**
     * By-ref recording event bus; $onDispatch runs inside the listener so
     * tests can observe what the world looks like at fan-out time.
     *
     * @param list<object> $events
     */
    private function recordingBus(array &$events, ?\Closure $onDispatch = null): EventBusInterface
    {
        return new class($events, $onDispatch) implements EventBusInterface {
            /** @var list<object> */
            private array $events; // @phpstan-ignore-line written through the by-ref binding, read by the test

            /** @param list<object> $events */
            public function __construct(array &$events, private readonly ?\Closure $onDispatch)
            {
                $this->events = &$events;
            }

            public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

            public function subscribe(EventSubscriberInterface $subscriber): void {}

            public function dispatchWithContext(object $event, EventContext $context): object
            {
                if ($this->onDispatch instanceof \Closure) {
                    ($this->onDispatch)($event);
                }
                $this->events[] = $event;

                return $event;
            }

            public function dispatch(object $event): object
            {
                return $this->dispatchWithContext($event, new EventContext(
                    bin2hex(random_bytes(16)),
                    0,
                    'c',
                ));
            }

            public function registrations(): array
            {
                return [];
            }

            public function freeze(): void {}
        };
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = [];
        foreach ($this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t ORDER BY id')) as $row) {
            self::assertIsString($row['name']);
            $names[] = $row['name'];
        }

        return $names;
    }
}

/**
 * Scenario command: outer step of the nested-dispatch test.
 */
final readonly class TxNestedOuter {}
