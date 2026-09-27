<?php

declare(strict_types=1);

/*
 * ZEF Framework — zone app-db-tx mutation-debt killers, accompanying the
 * issue #127 landing (UnitOfWork retry isolation).
 *
 * Targets (all escaped or not-covered in the 108-mutant zone measurement):
 * - UnitOfWork::flushRetrying() empty-queue fast path must open NO
 *   transaction (ReturnRemoval on the early return);
 * - the flushing flag must arm DURING a live flushRetrying so reentry
 *   throws (TrueValue on the flag assignment);
 * - the backoff sleep must be exactly delayMs(attempt) * 1000us with the
 *   real multiplication and 1000 factor (FunctionCallRemoval /
 *   Multiplication / Increment/DecrementInteger on the usleep line);
 * - TransactionManager's slow-hook debug line must compute
 *   ceil(elapsedNs / 1_000_000) with an int cast and a STRICT
 *   greater-than threshold comparison (RoundingFamily / CastInt /
 *   Increment/DecrementInteger / GreaterThan);
 * - TransactionalCommandBus must dispatch through withTransaction when no
 *   idempotency key is present, and flush via flushRetrying() when a retry
 *   policy is configured (two MethodCallRemoval on uncovered paths).
 *
 * Determinism: the Database-namespace hrtime/usleep shadows
 * (tests/Unit/database-shadow-functions.php) are armed per test via
 * globals and disarmed in tearDown.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\TransactionalCommandBus;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\Database\TransactionManager;
use Zef\Framework\Database\UnitOfWork;
use Zef\Framework\Database\UnitOfWorkRetryPolicy;

/**
 * @internal
 */
final class AppDbTxMutationDebtTest extends TestCase
{
    private ConnectionInterface $conn;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__zef_fake_hrtime'], $GLOBALS['__zef_db_usleep_calls']);
    }

    // ------------------------------------------------------------------
    // UnitOfWork::flushRetrying() boundaries.
    // ------------------------------------------------------------------

    public function testEmptyFlushRetryingOpensNoTransaction(): void
    {
        $spy = new SpyDbTxConnection();
        $uow = new UnitOfWork();
        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);

        self::assertSame(0, $uow->flushRetrying($spy, $policy));
        self::assertSame(0, $spy->begins, 'an empty queue must short-circuit before beginTransaction');
        self::assertSame(0, $spy->commits);
    }

    public function testReentryDuringLiveFlushRetryingThrows(): void
    {
        $innerResults = [];
        $depth = 0;
        $uow = new UnitOfWork();
        $uow->record(function (ConnectionInterface $c) use ($uow, &$innerResults, &$depth): void {
            ++$depth;
            if ($depth > 3) {
                return; // hard stop: only reachable on the mutated (broken) path
            }

            try {
                $uow->flushRetrying($c, new UnitOfWorkRetryPolicy(maxAttempts: 2, initialDelayMs: 0));
                $innerResults[] = 'no-throw';
            } catch (TransactionException) {
                $innerResults[] = 'threw';
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 2, initialDelayMs: 0);
        $executed = $uow->flushRetrying($this->conn, $policy);

        self::assertSame(1, $executed);
        self::assertSame(['threw'], $innerResults, 'reentry during a live flushRetrying must throw');
    }

    public function testRetryBackoffSleepsExactPolicyDelay(): void
    {
        $GLOBALS['__zef_db_usleep_calls'] = [];
        $attempt = 0;
        $uow = new UnitOfWork();
        $uow->record(function () use (&$attempt): void {
            ++$attempt;
            if ($attempt < 3) {
                throw $this->deadlock();
            }
        });

        $policy = new UnitOfWorkRetryPolicy(maxAttempts: 5, initialDelayMs: 100, maxDelayMs: 10_000, multiplier: 2.0, jitterMs: 0);
        $uow->flushRetrying($this->conn, $policy);

        self::assertSame(3, $attempt);
        self::assertSame([100_000, 200_000], $GLOBALS['__zef_db_usleep_calls'], 'backoff must sleep delayMs(attempt) * 1000 microseconds exactly');
    }

    public function testSlowHookLogsExactCeiledMilliseconds(): void
    {
        // 150_000_000 ns -> ceil(150.0) = 150 ms > 100 -> logged as int 150.
        $this->timedHooks([0, 150_000_000], static function (SpyDbTxLogger $logger): void {
            self::assertCount(1, $logger->debugCalls);
            self::assertSame([
                'afterCommit hook exceeded slow-hook threshold',
                ['elapsed_ms' => 150, 'threshold_ms' => 100],
            ], $logger->debugCalls[0]);
        });
    }

    public function testHookAtExactThresholdIsNotLogged(): void
    {
        // 100 ms elapsed vs 100 ms threshold: strictly greater is required.
        $this->timedHooks([0, 100_000_000], static function (SpyDbTxLogger $logger): void {
            self::assertSame([], $logger->debugCalls, 'elapsed == threshold must stay silent (strict >)');
        });
    }

    public function testSubMillisecondOverageCeilsUp(): void
    {
        // 100_000_001 ns -> ceil(100.000001) = 101 ms -> logged (floor/round would stay at 100).
        $this->timedHooks([0, 100_000_001], static function (SpyDbTxLogger $logger): void {
            self::assertCount(1, $logger->debugCalls);
            self::assertSame(101, $logger->debugCalls[0][1]['elapsed_ms']);
        });
    }

    public function testDivisorBoundaryBeforeExactMillisecond(): void
    {
        // 999_999_500 ns -> ceil(999.9995) = 1000 ms with the 1_000_000 divisor.
        $this->timedHooks([0, 999_999_500], static function (SpyDbTxLogger $logger): void {
            self::assertCount(1, $logger->debugCalls);
            self::assertSame(1000, $logger->debugCalls[0][1]['elapsed_ms']);
        });
    }

    public function testDivisorBoundaryAfterExactMillisecond(): void
    {
        // 1_000_001_000 ns -> ceil(1000.001) = 1001 ms with the 1_000_000 divisor.
        $this->timedHooks([0, 1_000_001_000], static function (SpyDbTxLogger $logger): void {
            self::assertCount(1, $logger->debugCalls);
            self::assertSame(1001, $logger->debugCalls[0][1]['elapsed_ms']);
        });
    }

    // ------------------------------------------------------------------
    // TransactionalCommandBus uncovered paths.
    // ------------------------------------------------------------------

    public function testPlainDispatchRunsInsideWithTransaction(): void
    {
        $uow = new UnitOfWork();
        $bus = new TransactionalCommandBus(new CommandBus(), new TransactionManager($this->conn), $uow);
        $bus->register(\stdClass::class, new readonly class($uow) implements CommandHandlerInterface {
            public function __construct(
                private UnitOfWork $uow,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['plain-uow']));

                return 'plain-result';
            }
        });

        self::assertSame('plain-result', $bus->dispatch(new \stdClass()), 'non-idempotent dispatch returns the handler result via withTransaction');
        self::assertSame(['plain-uow'], $this->names(), 'the deferred UoW write flushed before commit');
    }

    public function testCustomInnerBusDispatchRunsInsideWithTransaction(): void
    {
        // Covers the non-Concrete-CommandBus branch: a userland
        // CommandBusInterface double must still be wrapped by
        // withTransaction (and its deferred writes flushed) on dispatch.
        $uow = new UnitOfWork();
        $inner = new readonly class($uow) implements CommandBusInterface {
            public function __construct(private UnitOfWork $uow) {}

            #[\Override]
            public function register(string $commandClass, callable|CommandHandlerInterface $handler): void {}

            #[\Override]
            public function use(CqrsMiddlewareInterface $middleware): void {}

            #[\Override]
            public function dispatch(object $command, ?CqrsContext $context = null): mixed
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['custom-bus']));

                return 'custom-result';
            }

            #[\Override]
            public function freeze(): void {}

            #[\Override]
            public function isFrozen(): bool
            {
                return false;
            }
        };
        $bus = new TransactionalCommandBus($inner, new TransactionManager($this->conn), $uow);

        self::assertSame('custom-result', $bus->dispatch(new \stdClass()));
        self::assertSame(['custom-bus'], $this->names(), 'the custom inner bus runs inside withTransaction and its UoW write flushes');
        self::assertSame(0, $uow->pending());
    }

    public function testRetryPolicyRouteFlushesThroughFlushRetrying(): void
    {
        $uow = new UnitOfWork();
        $retry = new UnitOfWorkRetryPolicy(maxAttempts: 3, initialDelayMs: 0);
        $bus = new TransactionalCommandBus(new CommandBus(), new TransactionManager($this->conn), $uow, null, $retry);
        $bus->register(\stdClass::class, new readonly class($uow) implements CommandHandlerInterface {
            public function __construct(
                private UnitOfWork $uow,
            ) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                $this->uow->recordQuery(new SqlQuery('INSERT INTO t (name) VALUES (?)', ['retry-route']));

                return 'retry-result';
            }
        });

        self::assertSame('retry-result', $bus->dispatch(new \stdClass()));
        self::assertSame(['retry-route'], $this->names(), 'a configured retry policy must still flush the UoW queue');
        self::assertSame(0, $uow->pending());
    }

    // ------------------------------------------------------------------
    // TransactionManager slow-hook debug arithmetic.
    // ------------------------------------------------------------------

    /** @param list<int> $hrtimeSequenceNs two reads per timed hook */
    private function timedHooks(array $hrtimeSequenceNs, callable $assert): void
    {
        $reads = $hrtimeSequenceNs;
        $GLOBALS['__zef_fake_hrtime'] = ['fake' => static function () use (&$reads): int {
            return \array_shift($reads) ?? 0;
        }];
        $logger = new SpyDbTxLogger();
        $tm = new TransactionManager($this->conn, 100, $logger);

        $tm->withTransaction(static function () use ($tm): void {
            $tm->afterCommit(static function (): void {});
        });

        $assert($logger);
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

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

    private function deadlock(): \PDOException
    {
        return new class('deadlock', '40P01') extends \PDOException {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message, 0);
                $this->errorInfo = [$sqlState, 0, $message];
            }
        };
    }
}

/**
 * @internal
 */
final class SpyDbTxConnection implements ConnectionInterface
{
    public int $begins = 0;

    public int $commits = 0;

    public int $rollbacks = 0;

    #[\Override]
    public function execute(SqlQuery $query): int
    {
        return 0;
    }

    #[\Override]
    public function fetchAll(SqlQuery $query): array
    {
        return [];
    }

    #[\Override]
    public function fetchOne(SqlQuery $query): ?array
    {
        return null;
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return null;
    }

    #[\Override]
    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        ++$this->begins;
    }

    #[\Override]
    public function commit(): void
    {
        ++$this->commits;
    }

    #[\Override]
    public function rollBack(): void
    {
        ++$this->rollbacks;
    }

    #[\Override]
    public function transactionLevel(): int
    {
        return 0;
    }

    #[\Override]
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        return $fn($this);
    }
}

/**
 * @internal
 */
final class SpyDbTxLogger extends AbstractLogger
{
    /** @var list<array{string, array<array-key, mixed>}> */
    public array $debugCalls = [];

    /**
     * PSR-3 contract: $level is mixed (a string level like 'debug',
     * or a Level enum in psr/log v3); only DEBUG records are kept.
     *
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::DEBUG) {
            $this->debugCalls[] = [(string) $message, $context];
        }
    }
}
