<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 7c — Application/CQRS + Event (ronde 3).
 *
 * Kurikulum chunk f7-rest-b (53 escape baseline): depth guard dua dispatcher
 * (batas tepat 64 via rekursi terhitung, pesan persis, finally-decrement
 * kebocoran), EventRegistration ordering (priority desc + sequence asc,
 * freeze sort + registrations() order), resolved-cache konsistensi antar
 * dispatch, context-aware listener invocation, error aggregation pesan
 * exact, EventContext traceId 32-hex + occurredAt skala nanodetik, CQRS
 * idempotency (key guard dua lapis, TTL guard, expired-recompute pada
 * detik boundary, evict capacity 10.000 tepat + maxKeyLength 129), command
 * fan-out after-cache tanpa eventBus, query bus guard (validate, conflict,
 * mutable, context eksplisit dipakai apa adanya).
 *
 * Setiap test membunuh mutan spesifik dari build/infection-f7-rest-b.log.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\CommandHandlerInterface;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\CqrsEventResult;
use Zef\Framework\CQRS\CqrsHandlerConflictException;
use Zef\Framework\CQRS\CqrsMiddlewareInterface;
use Zef\Framework\CQRS\IdempotencyStoreInterface;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\QueryBus;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventDispatchException;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Event\EventSubscriberInterface;

final class F7C_Command {}

final class F7C_Command2 {}

final class F7C_Query {}

final class F7C_Event {}

final class F7C_StoppableEvent implements StoppableEventInterface
{
    public function __construct(private readonly bool $stopped = false) {}

    #[\Override]
    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class F7C_MutableEvent
{
    /** @var list<string> */
    public array $log = [];
}

final class F7C_Subscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function subscriptions(): array
    {
        return [
            F7C_MutableEvent::class => [
                [0, static function (F7C_MutableEvent $e): void {
                    $e->log[] = 'first';
                }],
                [-5, static function (F7C_MutableEvent $e): void {
                    $e->log[] = 'low-priority';
                }],
            ],
        ];
    }
}

/**
 * @internal
 */
final class EdgeMatrixF7CqrsEventTest extends TestCase
{
    // --------------------------------------------- EventDispatcher: registration

    public function testDispatcherRejectsUnknownEventClass(): void
    {
        $dispatcher = new EventDispatcher();

        try {
            $dispatcher->listen('', static fn (): null => null);
            self::fail('Expected InvalidArgumentException for empty event class.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Unknown event class ''.", $e->getMessage());
        }

        try {
            $dispatcher->listen('NoSuch\Class', static fn (): null => null);
            self::fail('Expected InvalidArgumentException for unknown event class.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Unknown event class 'NoSuch\\Class'.", $e->getMessage());
        }
    }

    public function testDispatcherPriorityOrderAndFreezeSort(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->listen(F7C_Event::class, static function () use (&$seen): void {
            $seen[] = 'a0';
        }, 0);
        $dispatcher->listen(F7C_Event::class, static function () use (&$seen): void {
            $seen[] = 'b10';
        }, 10);
        $dispatcher->listen(F7C_Event::class, static function () use (&$seen): void {
            $seen[] = 'c0';
        }, 0);
        $dispatcher->dispatch(new F7C_Event());
        self::assertSame(['b10', 'a0', 'c0'], $seen, 'priority desc, then registration order');
        $dispatcher->freeze();
        self::assertTrue($dispatcher->isFrozen());
        // Registrations() setelah freeze wajib mengikuti urutan prioritas
        // (usort di freeze), bukan urutan pendaftaran.
        $order = array_map(static fn (EventRegistration $r): int => $r->priority, $dispatcher->registrations());
        self::assertSame([10, 0, 0], $order, 'freeze() must sort registrations per event class');

        try {
            $dispatcher->listen(F7C_Event::class, static fn (): null => null);
            self::fail('Expected LogicException after freeze.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    public function testDispatcherSubscriberRegistersAndGuards(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->subscribe(new F7C_Subscriber());
        $event = new F7C_MutableEvent();
        $dispatcher->dispatch($event);
        self::assertSame(['first', 'low-priority'], $event->log, 'priority 0 before -5');

        $bad = new EventDispatcher();

        try {
            $bad->subscribe(new class implements EventSubscriberInterface {
                #[\Override]
                public static function subscriptions(): array
                {
                    return [ // @phpstan-ignore-line
                        F7C_Event::class => [
                            [10, 'not-callable-at-all'],
                        ],
                    ];
                }
            });
            self::fail('Expected InvalidArgumentException for non-callable subscriber listener.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Event subscriber listener must be callable.', $e->getMessage());
        }
    }

    public function testDispatcherStoppedEventSkipsAllListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;
        $dispatcher->listen(F7C_StoppableEvent::class, static function () use (&$calls): void {
            ++$calls;
        });
        $dispatcher->dispatch(new F7C_StoppableEvent(true));
        self::assertSame(0, $calls, 'pre-stopped events must not reach any listener');
    }

    public function testDispatcherDepthLimitIsExactly64AndResets(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;
        $dispatcher->listen(F7C_Event::class, static function () use ($dispatcher, &$calls): void {
            ++$calls;
            $dispatcher->dispatch(new F7C_Event());
        });

        try {
            $dispatcher->dispatch(new F7C_Event());
            self::fail('Expected the depth guard to trip.');
        } catch (EventDispatchException $e) {
            // rantai previous wajib berujung pada LogicException depth guard
            $deepest = $e;
            while ($deepest->getPrevious() instanceof \Throwable) {
                $deepest = $deepest->getPrevious();
            }
            self::assertInstanceOf(\LogicException::class, $deepest);
            self::assertSame('Event dispatch depth exceeded (recursive dispatch?).', $deepest->getMessage());
        }
        self::assertSame(64, $calls, 'listeners run for depth entries 1..64; entry #65 (depth 64) throws');
        // finally wajib mengembalikan depth ke 0 — dispatch berikutnya normal.
        $ok = 0;
        $dispatcher->listen(F7C_StoppableEvent::class, static function () use (&$ok): void {
            ++$ok;
        });
        $dispatcher->dispatch(new F7C_StoppableEvent(false));
        self::assertSame(1, $ok, 'depth must be reset after the recursive failure');
    }

    public function testDispatcherContextShapeAndErrorAggregation(): void
    {
        $dispatcher = new EventDispatcher();
        $contexts = [];
        $dispatcher->listen(F7C_Event::class, static function (F7C_Event $e, EventContext $ctx) use (&$contexts): void {
            $contexts[] = $ctx;
        });
        $dispatcher->listen(F7C_Event::class, static function (F7C_Event $e, EventContext $ctx): never {
            throw new \RuntimeException('listener-2 blew up');
        });
        $nowNs = (int) (microtime(true) * 1_000_000_000);

        try {
            $dispatcher->dispatch(new F7C_Event());
            self::fail('Expected EventDispatchException aggregating listener failures.');
        } catch (EventDispatchException $e) {
            self::assertSame('Event listener failed for ' . F7C_Event::class . '.', $e->getMessage());
            self::assertCount(1, $e->errors);
            self::assertSame('listener-2 blew up', $e->errors[0]->getMessage());
            self::assertInstanceOf(F7C_Event::class, $e->event);
        }
        self::assertCount(1, $contexts, 'context-aware listener ran before the failing one');
        $ctx = $contexts[0];
        self::assertSame(32, strlen($ctx->eventId));
        self::assertTrue(ctype_xdigit($ctx->eventId), 'eventId = bin2hex(random_bytes(16))');
        self::assertGreaterThan(1_000_000_000_000_000_000, $ctx->occurredAtUnixNano, 'occurredAt is nanoseconds since epoch');
        // abs() matters: when both clock reads land in the same microsecond
        // (routine on Windows' coarse timers), the raw delta is exactly 0 and
        // the old `delta + 1_000_000_000 < 1_000_000_000` form failed on the
        // equality boundary — a pure timing flake, not a timestamp regression.
        self::assertLessThan(1_000_000_000, abs($nowNs - $ctx->occurredAtUnixNano), 'occurredAt is within ~a second of now');
    }

    public function testDispatcherResolvedCacheIsConsistentAcrossDispatches(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;
        $dispatcher->listen(F7C_Event::class, static function () use (&$calls): void {
            ++$calls;
        });
        $dispatcher->freeze();
        $dispatcher->dispatch(new F7C_Event());
        $dispatcher->dispatch(new F7C_Event());
        self::assertSame(2, $calls, 'frozen cache must return the full listener list on every dispatch');
    }

    public function testDispatcherRegistrationsExposePrioritiesAndContextFlag(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(F7C_Event::class, static fn (F7C_Event $e, EventContext $c): null => null, 3);
        $regs = $dispatcher->registrations();
        self::assertCount(1, $regs);
        self::assertSame(3, $regs[0]->priority);
        self::assertSame(F7C_Event::class, $regs[0]->eventClass);
        self::assertTrue($regs[0]->acceptsContext, 'two-parameter listener accepts context');
    }

    public function testCommandBusDepthGuardMessageAndReset(): void
    {
        $bus = new CommandBus();
        $calls = 0;
        $bus->register(F7C_Command::class, $this->commandHandler(static function () use ($bus, &$calls): mixed {
            ++$calls;

            return $bus->dispatch(new F7C_Command());
        }));

        try {
            $bus->dispatch(new F7C_Command());
            self::fail('Expected LogicException from the CQRS depth guard.');
        } catch (\LogicException $e) {
            self::assertSame(CommandBus::class . ' dispatch depth exceeded (recursive dispatch?).', $e->getMessage());
        }
        self::assertSame(64, $calls, 'trait guard throws at ++depth > 64: 64 handler calls succeed first');
    }

    public function testCommandBusDepthIsResetAfterGuardTrip(): void
    {
        // Bus yang SAMA: guard trip -> dispatch normal -> guard trip lagi.
        // finally-decrement wajib mengembalikan depth persis ke nol.
        $bus = new CommandBus();
        $calls = 0;
        $bus->register(F7C_Command::class, $this->commandHandler(static function () use ($bus, &$calls): mixed {
            ++$calls;

            return $bus->dispatch(new F7C_Command());
        }));
        $bus->register(F7C_Command2::class, $this->commandHandler(static fn (): string => 'ok'));

        try {
            $bus->dispatch(new F7C_Command());
        } catch (\LogicException) {
            // trip pertama
        }
        self::assertSame('ok', $bus->dispatch(new F7C_Command2()), 'depth must be fully reset after the guard trip');

        try {
            $bus->dispatch(new F7C_Command());
        } catch (\LogicException) {
            // trip kedua
        }
        self::assertSame(128, $calls, 'second trip needs exactly 64 more handler calls: no depth residue');
    }

    public function testCommandBusFrozenGuardAndTtlGuard(): void
    {
        $bus = new CommandBus();
        $bus->freeze();
        self::assertTrue($bus->isFrozen());

        try {
            $bus->register(F7C_Command::class, static fn (): null => null);
            self::fail('Expected LogicException: frozen command bus.');
        } catch (\LogicException $e) {
            self::assertSame(CommandBus::class . ' is frozen.', $e->getMessage());
        }

        self::addToAssertionCount(1); // TTL 1 valid
        new CommandBus(idempotencyTtlSeconds: 1)->freeze();

        try {
            new CommandBus(idempotencyTtlSeconds: 0);
            self::fail('Expected InvalidArgumentException for TTL 0.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('CQRS idempotency TTL must be positive.', $e->getMessage());
        }
    }

    public function testCommandBusIdempotentReplayUsesCacheAndFanoutOnce(): void
    {
        $store = new InMemoryIdempotencyStore();
        $executions = 0;
        $fired = 0;
        $eventBus = new EventDispatcher();
        $eventBus->listen(F7C_Event::class, static function () use (&$fired): void {
            ++$fired;
        });
        $bus = new CommandBus($store, 3600, $eventBus);
        $bus->register(F7C_Command::class, $this->commandHandler(static function () use (&$executions): mixed {
            ++$executions;

            return new CqrsEventResult('ok-1', [new F7C_Event()]);
        }));
        $context = new CqrsContext('corr-00001', null, 'idem-00001');
        $r1 = $bus->dispatch(new F7C_Command(), $context);
        self::assertSame('ok-1', $r1);
        self::assertSame(1, $executions);
        self::assertSame(1, $fired, 'events fan out exactly once for the first caller');
        $r2 = $bus->dispatch(new F7C_Command(), $context);
        self::assertSame('ok-1', $r2, 'idempotent replay returns the cached result');
        self::assertSame(1, $executions, 'replay must NOT re-run the handler');
        self::assertSame(1, $fired, 'replay must NOT re-fire events');
    }

    public function testCommandBusWithoutStoreRunsHandlerEvenWithIdempotencyKey(): void
    {
        $bus = new CommandBus();
        $bus->register(F7C_Command::class, $this->commandHandler(static fn (): string => 'ran'));
        $context = new CqrsContext('corr-00002', null, 'idem-00002');
        self::assertSame('ran', $bus->dispatch(new F7C_Command(), $context), 'no store configured: execution proceeds normally');
    }

    public function testCommandBusWithoutEventBusSkipsFanout(): void
    {
        $bus = new CommandBus();
        $bus->register(F7C_Command::class, $this->commandHandler(static fn (): CqrsEventResult => new CqrsEventResult('no-bus', [new F7C_Event()])));
        self::assertSame('no-bus', $bus->dispatch(new F7C_Command()), 'null event bus must not crash the fan-out');
    }

    public function testCommandBusTtlIsPassedThroughToStore(): void
    {
        $store = new class implements IdempotencyStoreInterface {
            public array $ttls = []; // @phpstan-ignore-line

            #[\Override]
            public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
            {
                $this->ttls[] = $ttlSeconds;

                return $producer();
            }
        };
        $bus = new CommandBus($store, 777);
        $bus->register(F7C_Command::class, $this->commandHandler(static fn (): string => 'x'));
        $bus->dispatch(new F7C_Command(), new CqrsContext('corr-00003', null, 'idem-00003'));
        self::assertSame([777], $store->ttls, 'configured TTL must reach the store');
    }

    // --------------------------------------------- QueryBus

    public function testQueryBusRegistrationGuards(): void
    {
        $bus = new QueryBus();

        try {
            $bus->register('bad query class', static fn (): null => null);
            self::fail('Expected InvalidArgumentException for invalid query class.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("Invalid query class 'bad query class'.", $e->getMessage());
        }
        $bus->register(F7C_Query::class, static fn (): int => 1);

        try {
            $bus->register(F7C_Query::class, static fn (): int => 2);
            self::fail('Expected CqrsHandlerConflictException on duplicate registration.');
        } catch (CqrsHandlerConflictException $e) {
            self::assertSame("Query handler already registered for '" . F7C_Query::class . "'.", $e->getMessage());
        }
        $bus->freeze();

        try {
            $bus->use(new class implements CqrsMiddlewareInterface {
                #[\Override]
                public function process(object $message, CqrsContext $context, \Closure $next): mixed
                {
                    return $next($message, $context);
                }
            });
            self::fail('Expected LogicException: frozen query bus.');
        } catch (\LogicException $e) {
            self::assertSame(QueryBus::class . ' is frozen.', $e->getMessage());
        }
    }

    public function testQueryBusUsesExplicitContext(): void
    {
        $bus = new QueryBus();
        $seenContext = null;
        $bus->register(F7C_Query::class, static function (F7C_Query $q, CqrsContext $ctx) use (&$seenContext): string {
            $seenContext = $ctx;

            return 'answer';
        });
        $explicit = new CqrsContext('corr-00004', null, null, ['tenant' => 'acme']);
        $answer = $bus->ask(new F7C_Query(), $explicit);
        self::assertSame('answer', $answer);
        self::assertSame($explicit, $seenContext, 'the caller-provided context object must reach the handler untouched');
        self::assertSame('acme', $seenContext->attributes['tenant']);
    }

    // --------------------------------------------- InMemoryIdempotencyStore (CQRS)

    public function testCqrsStoreCapacityBoundaryAndGuards(): void
    {
        try {
            new InMemoryIdempotencyStore(0);
            self::fail('Expected InvalidArgumentException for capacity 0.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Idempotency store capacity must be positive.', $e->getMessage());
        }
        self::addToAssertionCount(1); // capacity 1 valid
        $store = new InMemoryIdempotencyStore(1);
        $store->remember('key-0001', static fn (): string => 'v1');
        self::assertSame('v2', $store->remember('key-0002', static fn (): string => 'v2'), 'capacity 1 evicts the oldest entry');
        self::assertSame('v2', $store->remember('key-0002', static fn (): string => 'v3'), 'newest entry stays cached');
    }

    public function testCqrsStoreKeyGrammarIsOpaqueId(): void
    {
        $store = new InMemoryIdempotencyStore();
        $calls = 0;
        $producer = static function () use (&$calls): string {
            ++$calls;

            return 'v';
        };

        try {
            $store->remember('', $producer);
            self::fail('Expected InvalidArgumentException for empty key.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid idempotency key.', $e->getMessage());
        }

        try {
            $store->remember('bad key!', $producer);
            self::fail('Expected InvalidArgumentException for non-opaque key.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid idempotency key.', $e->getMessage());
        }
        self::assertSame(0, $calls, 'rejected keys must not run the producer');

        try {
            $store->remember('key-0009', $producer, 0);
            self::fail('Expected InvalidArgumentException for TTL 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::addToAssertionCount(1); // 128-char key accepted
        $store->remember(str_repeat('k', 128), $producer);

        try {
            $store->remember(str_repeat('k', 129), $producer);
            self::fail('Expected InvalidArgumentException for 129-char key.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testCqrsStoreEvictsOldestExactlyAtDefaultCapacity(): void
    {
        $store = new InMemoryIdempotencyStore();
        $calls = 0;
        $producer = static function () use (&$calls): string {
            ++$calls;

            return 'v' . $calls;
        };
        $bulk = static fn (): string => 'bulk';
        self::assertSame('v1', $store->remember('key-0001', $producer));
        for ($i = 1; $i <= 9_999; ++$i) {
            $store->remember('blk-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), $bulk);
        }
        self::assertSame('v1', $store->remember('key-0001', $producer), 'at 10.000 entries the oldest is still cached');
        self::assertSame(1, $calls);
        $store->remember('blk-010000', $bulk);
        self::assertSame('v2', $store->remember('key-0001', $producer), 'the 10.001st insert evicts the oldest');
        self::assertSame(2, $calls);
    }

    public function testCqrsStoreRecomputesExactlyWhenEntryExpires(): void
    {
        $store = new InMemoryIdempotencyStore();
        $calls = 0;
        $producer = static function () use (&$calls): string {
            ++$calls;

            return 'v' . $calls;
        };
        self::assertSame('v1', $store->remember('key-0001', $producer, 1)); // expiresAt = t0 + 1
        $t0 = time();
        while (time() === $t0) {
            // maju ke detik expiry: expiresAt == now -> purge (<=, bukan <)
        }
        self::assertSame('v2', $store->remember('key-0001', $producer, 1), 'expired entry must run the producer again');
        self::assertSame(2, $calls);
    }

    // --------------------------------------------- CommandBus

    private function commandHandler(\Closure $fn): CommandHandlerInterface
    {
        return new readonly class($fn) implements CommandHandlerInterface {
            public function __construct(private \Closure $fn) {}

            #[\Override]
            public function __invoke(object $command, CqrsContext $context): mixed
            {
                return ($this->fn)($command, $context);
            }
        };
    }
}
