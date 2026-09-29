<?php

declare(strict_types=1);

/*
 * Regression suite for GitHub issue #176 — [NITPICK][ZEF-DEEP-22]
 * Nitpicks roundup: the behaviour-changing halves only (N-9, N-10, N-11,
 * N-12, N-15, N-16, N-17, N-18, N-19 plus the N-6 lazy-sweep guard).
 * Docblock/disposition-only findings (N-1..N-5, N-7 annotations, N-8,
 * N-13, N-14, N-20, Sonar S5850) are covered by existing suites or are
 * documentation by design — see the worklog for the per-finding table.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Zef\Framework\Cache\LockStoreInterface;
use Zef\Framework\Container\ServiceRegistrar;
use Zef\Framework\Container\ServiceRegistry;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Exception\InvalidFactoryException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\JobTimeoutException;
use Zef\Framework\Job\LockingJobIdempotencyStore;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Job\Scheduler;
use Zef\Framework\Router\Router;
use Zef\Framework\Runtime\Async\DeadlockException;
use Zef\Framework\Runtime\Async\FiberScheduler;
use Zef\Framework\Runtime\Async\UnobservedTaskException;
use Zef\Framework\Runtime\Async\WaitGroup;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Test\Unit\CollectingLogger;

/**
 * @internal
 */
final class NitpicksIssue176RegressionTest extends TestCase
{
    // ------------------------------------------------------------------
    // N-6: BoundedInMemoryReplayProtector lazy sweep
    // ------------------------------------------------------------------

    public function testReplayProtectorLazySweepPreservesDecisionSemantics(): void
    {
        $protector = new BoundedInMemoryReplayProtector(2, 10);

        // Fresh entries fill the capacity: the next id is UNAVAILABLE
        // (the lazy sweep finds nothing to evict).
        self::assertSame('ACCEPT', $protector->check('a', 1000)->decision->name);
        self::assertSame('ACCEPT', $protector->check('b', 1000)->decision->name);
        self::assertSame('UNAVAILABLE', $protector->check('c', 1001)->decision->name);

        // Long after the window lapsed, capacity pressure triggers the
        // sweep, both stale entries evaporate and the id is admitted —
        // identical to the eager per-request sweep semantics.
        self::assertSame('ACCEPT', $protector->check('c', 5000)->decision->name);
        self::assertSame('DUPLICATE', $protector->check('c', 5005)->decision->name);

        // A stale entry re-presented is evicted on touch and re-accepted.
        self::assertSame('ACCEPT', $protector->check('a', 9000)->decision->name);
    }

    // ------------------------------------------------------------------
    // N-9: Router duplicate-slash collapse
    // ------------------------------------------------------------------

    public function testRouterCollapsesDuplicateSlashesLikeTrailingSlashes(): void
    {
        // Regresi N-9 (issue #176): trailing slashes were tolerated while
        // duplicate slashes 404'd — both now collapse to the canonical
        // single-slash form.
        $router = new Router();
        $router->add('GET', '/users/{id:int}', 'users.show');
        $router->add('GET', '/reports/summary', 'reports.summary');
        $router->add('GET', '/a//b', 'double.pattern');

        $match = $router->match('GET', '/users//123');
        self::assertSame('users.show', $match['handler']);
        self::assertSame('123', $match['params']['id']);

        self::assertSame('reports.summary', $router->match('GET', '/reports//summary')['handler']);
        self::assertSame('reports.summary', $router->match('GET', '//reports/summary')['handler']);
        self::assertSame('users.show', $router->match('GET', '/users/123/')['handler'], 'trailing slash stays tolerated');

        // A pattern containing '//' is equivalent to its collapsed form.
        self::assertSame('double.pattern', $router->match('GET', '/a/b')['handler']);
        self::assertSame('double.pattern', $router->match('GET', '/a//b')['handler']);
    }

    public function testRouterNoLongerMatchesEmptyDynamicSegments(): void
    {
        // Regresi N-9 (issue #176): an empty path segment used to satisfy
        // an unconstrained dynamic parameter ('' for {name}) while static
        // segments 404'd — the collapse makes both sides consistently
        // reject the degenerate form.
        $router = new Router();
        $router->add('GET', '/users/{name}/posts', 'users.posts');

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/users//posts');
    }

    // ------------------------------------------------------------------
    // N-10: MessageBase::bodyString() narrowed catch
    // ------------------------------------------------------------------

    public function testBodyStringDegradesOnRuntimeExceptionButPropagatesLogicErrors(): void
    {
        // Regresi N-10 (issue #176): PSR-7 I/O failures (RuntimeException
        // family) still degrade to '' ...
        $runtimeFailure = new Response(200, [], new ThrowingBodyStream(\RuntimeException::class));
        self::assertSame('', $runtimeFailure->bodyString());

        // ... but programming errors are no longer silently swallowed.
        $logicFailure = new Response(200, [], new ThrowingBodyStream(\LogicException::class));

        try {
            $logicFailure->bodyString();
            self::fail('non-runtime stream failures must propagate out of bodyString()');
        } catch (\LogicException $e) {
            self::assertSame('stream boom', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // N-11: RequestFactory / Uri host grammar alignment
    // ------------------------------------------------------------------

    public function testFactoryAndUriAcceptTheSameUnderscoreHosts(): void
    {
        // Regresi N-11 (issue #176): Uri's RFC 3986 reg-name grammar
        // accepts '_' while the factory's DNS-only check rejected it —
        // both layers now agree (RFC 9110 Host = reg-name, '_' is
        // unreserved).
        $request = RequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'my_service.internal',
        ]);
        self::assertSame('my_service.internal', $request->getUri()->getHost());

        $uri = new Uri('http://my_service.internal/');
        self::assertSame('my_service.internal', $uri->getHost());
        self::assertSame('http://my_service.internal/', (string) $uri);
    }

    // ------------------------------------------------------------------
    // N-12: FiberScheduler deadlock-abort failure surfacing
    // ------------------------------------------------------------------

    public function testDeadlockSurfacesUnobservedFailuresAndChainsTheDeadlockReason(): void
    {
        // Regresi N-12 (issue #176): the deadlock-abort path skipped
        // surfaceFailures(), discarding the unobserved failure the run
        // was already carrying. The failure now surfaces first and the
        // deadlock stays observable as the previous exception.
        $scheduler = new FiberScheduler();
        $waitGroup = new WaitGroup($scheduler);
        $waitGroup->add(1);

        try {
            $scheduler->run(static function () use ($scheduler, $waitGroup): string {
                $scheduler->spawn(static function (): never {
                    throw new \RuntimeException('side-boom');
                }, 'side');
                $waitGroup->await(); // parks forever — nothing can wake main

                return 'never';
            });
            self::fail('the unobserved failure must surface ahead of the deadlock');
        } catch (UnobservedTaskException $exception) {
            self::assertStringContainsString('side-boom', $exception->getMessage());
            $deadlock = $exception->getPrevious();
            self::assertInstanceOf(DeadlockException::class, $deadlock);
            self::assertStringContainsString('main', $deadlock->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // N-15: PdoConnection::lastInsertId() exception mapping
    // ------------------------------------------------------------------

    public function testLastInsertIdMapsPdoFailuresOntoQueryException(): void
    {
        // Regresi N-15 (issue #176): pgsql lastval() is undefined until
        // the session's first INSERT — the raw PDOException leaked; it is
        // now mapped onto the ConnectionException family like every other
        // PDO call, with the original chained.
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('lastInsertId')
            ->willThrowException(new \PDOException('lastval is not yet defined in this session'))
        ;
        $connection = new PdoConnection(
            ConnectionConfig::fromArray(['driver' => 'pgsql', 'host' => 'h', 'dbname' => 'd']),
            $pdo,
        );

        try {
            $connection->lastInsertId();
            self::fail('a failing PDO::lastInsertId must surface as QueryException');
        } catch (QueryException $e) {
            self::assertStringContainsString('last insert ID', $e->getMessage());
            self::assertStringContainsString('lastval is not yet defined', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    // ------------------------------------------------------------------
    // N-16: LockingJobIdempotencyStore logger port
    // ------------------------------------------------------------------

    public function testIdempotencyReleaseFailureGoesThroughTheLoggerPort(): void
    {
        // Regresi N-16 (issue #176): the release-failure report went
        // through raw error_log(); with a PSR-3 logger injected it is now
        // routed through the port (the no-logger error_log fallback is
        // pinned by LockingJobIdempotencyStoreTest).
        $logger = new CollectingLogger();
        $store = new class implements LockStoreInterface {
            #[\Override]
            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return true;
            }

            #[\Override]
            public function release(string $key, string $owner): bool
            {
                throw new \RuntimeException('store outage');
            }

            #[\Override]
            public function refresh(string $key, string $owner, int $ttlSeconds): bool
            {
                return false;
            }

            #[\Override]
            public function holder(string $key): ?string
            {
                return null;
            }
        };
        $idempotency = new LockingJobIdempotencyStore($store, $logger);

        try {
            $idempotency->remember('job|abc', static fn (): never => throw new \RuntimeException('producer-boom'), 60);
            self::fail('the producer failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('producer-boom', $e->getMessage());
        }

        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('lease release failed', $logger->errors[0]);
        self::assertStringContainsString('job|abc', $logger->errors[0]);
        self::assertStringContainsString('store outage', $logger->errors[0]);
    }

    // ------------------------------------------------------------------
    // N-17: InProcessJobWorker deadline wiring
    // ------------------------------------------------------------------

    public function testWorkerDeadlineMakesJobTimeoutExceptionReachable(): void
    {
        // Regresi N-17 (issue #176): the built-in worker never set a
        // deadline, so JobContext::throwIfCancelled() could never raise
        // JobTimeoutException on the worker path. jobTimeoutMs now flows
        // into the context; null keeps the legacy no-timeout behaviour.
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker(
            $queue,
            new RetryPolicy(maxAttempts: 1),
            jobTimeoutMs: 1,
        );
        $worker->register(
            'slow.job',
            static function (JobEnvelope $job, JobContext $context): string {
                usleep(3000); // 3ms — past the 1ms deadline
                $context->throwIfCancelled();

                return 'never';
            },
        );
        $queue->enqueue(new JobEnvelope(
            jobId: 'job-timeout-1',
            jobType: 'slow.job',
            payload: null,
            availableAtUnixNano: 0,
        ));

        $result = $worker->processOne();

        self::assertInstanceOf(JobResult::class, $result);
        self::assertFalse($result->completed);
        self::assertInstanceOf(JobTimeoutException::class, $result->result);
        self::assertFalse($result->willRetry, 'maxAttempts=1 makes the timeout terminal');

        // The deadline knob validates its own contract.
        try {
            new InProcessJobWorker($queue, new RetryPolicy(), jobTimeoutMs: 0);
            self::fail('a zero deadline must be rejected at construction');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Job timeout must be positive.', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // N-18: Scheduler tick(0)
    // ------------------------------------------------------------------

    public function testSchedulerTickZeroIsAWellDefinedZeroEnqueuePass(): void
    {
        // Regresi N-18 (issue #176): tick(0) forwarded -1 to
        // CronExpression::nextRunAfter(), which rejects negatives — the
        // epoch-origin tick threw instead of initialising cursors.
        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $scheduler->register('report.build', null, CronExpression::parse('*/5 * * * *'));

        self::assertSame(0, $scheduler->tick(0));
        self::assertNull($queue->dequeue());
        self::assertNotNull($scheduler->nextRunOf('report.build'));
        self::assertGreaterThan(0, $scheduler->nextRunOf('report.build'));

        // Ordinary ticks are unaffected: the first */5 boundary enqueues.
        self::assertSame(1, $scheduler->tick(300 * 1_000_000_000));
    }

    // ------------------------------------------------------------------
    // N-19: ServiceRegistrar empty-ID diagnostics
    // ------------------------------------------------------------------

    public function testServiceRegistrarReportsEmptyIdsAsInvalidNotAlreadyRegistered(): void
    {
        // Regresi N-19 (issue #176): an empty identifier was reported as
        // "already registered" — describing a collision that never
        // happened. The duplicate-collision message stays untouched.
        $registrar = new ServiceRegistrar(new ServiceRegistry());

        try {
            $registrar->register('', static fn (): string => 'x');
            self::fail('an empty service ID must be rejected');
        } catch (InvalidFactoryException $e) {
            self::assertStringContainsString('service ID must not be empty', $e->getMessage());
            self::assertStringNotContainsString('already registered', $e->getMessage());
        }

        try {
            $registrar->alias('', 'target');
            self::fail('an empty alias must be rejected');
        } catch (InvalidFactoryException $e) {
            self::assertStringContainsString('alias must not be empty', $e->getMessage());
            self::assertStringNotContainsString('already registered', $e->getMessage());
        }

        $registrar->register('svc.a', static fn (): string => 'a');

        try {
            $registrar->register('svc.a', static fn (): string => 'b');
            self::fail('a genuine duplicate must still be rejected');
        } catch (InvalidFactoryException $e) {
            self::assertStringContainsString('service ID already registered', $e->getMessage());
        }
    }
}

/**
 * N-10 (issue #176): a PSR-7 stream double whose getContents() fails with
 * a configurable exception class — RuntimeException models an I/O failure
 * (degrades to ''), LogicException models a programming error (must
 * propagate).
 *
 * @internal
 */
final class ThrowingBodyStream implements StreamInterface
{
    /** @param class-string<\Throwable> $exceptionClass */
    public function __construct(private readonly string $exceptionClass) {}

    #[\Override]
    public function __toString(): string
    {
        return '';
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach(): mixed
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return 0;
    }

    #[\Override]
    public function eof(): bool
    {
        return false;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void {}

    #[\Override]
    public function rewind(): void {}

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        return 0;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        return '';
    }

    #[\Override]
    public function getContents(): string
    {
        throw new $this->exceptionClass('stream boom');
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
