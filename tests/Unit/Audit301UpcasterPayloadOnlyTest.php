<?php

declare(strict_types=1);

/*
 * Audit #301 regression: a payload/metadata-only upcaster (contract-legal
 * per UpcasterInterface — "It may rename the event or reshape payload /
 * metadata") must run its batch EXACTLY ONCE and finish, not loop to
 * MAX_HOPS and throw a false "rename cycle?". Real rename chains and real
 * rename cycles keep their documented semantics.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\EventUpcaster;
use Zef\Framework\EventSourcing\StoredEvent;
use Zef\Framework\EventSourcing\UpcasterInterface;

/**
 * @internal
 */
final class Audit301UpcasterPayloadOnlyTest extends TestCase
{
    public function testPayloadOnlyUpcasterRunsOnceAndReshapesPayload(): void
    {
        $calls = 0;
        $registry = new EventUpcaster(new class($calls) implements UpcasterInterface {
            public function __construct(private int &$calls) {}

            #[\Override]
            public function eventTypes(): array
            {
                return ['e.legacy'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                ++$this->calls;

                return Audit301Rebuilder::rebuild($event, 'e.legacy', ['v2' => true]);
            }
        });

        $legacy = $this->stored('e.legacy', 1, 1, ['old_value' => 7]);

        $upcast = $registry->transform($legacy);

        self::assertSame(1, $calls, 'the payload-only batch must run exactly once, not loop to MAX_HOPS');
        self::assertSame('e.legacy', $upcast->eventType);
        self::assertSame(['v2' => true], $upcast->payload);
        self::assertSame($legacy->eventId, $upcast->eventId, 'identity fields stay frozen');
        self::assertSame($legacy->version, $upcast->version);
        self::assertSame($legacy->globalSequence, $upcast->globalSequence);
    }

    public function testPayloadOnlyThenRenameChainStillComposes(): void
    {
        $registry = new EventUpcaster(
            new class implements UpcasterInterface {
                #[\Override]
                public function eventTypes(): array
                {
                    return ['e.v1'];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return Audit301Rebuilder::rebuild($event, 'e.v1', ['reshaped' => true]);
                }
            },
            new class implements UpcasterInterface {
                #[\Override]
                public function eventTypes(): array
                {
                    return ['e.v1', 'e.v2'];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return Audit301Rebuilder::rebuild($event, 'e.v2', $event->payload + ['migrated' => true]);
                }
            },
            new class implements UpcasterInterface {
                #[\Override]
                public function eventTypes(): array
                {
                    return ['e.v2'];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return Audit301Rebuilder::rebuild($event, 'e.v3', $event->payload + ['final' => true]);
                }
            },
        );

        $upcast = $registry->transform($this->stored('e.v1', 1, 1, ['old' => 1]));

        self::assertSame('e.v3', $upcast->eventType, 'the chain keeps walking across renames');
        // Batch e.v1: upcaster1 replaces the payload, upcaster2 renames to
        // e.v2 and adds 'migrated'; batch e.v2: upcaster3 renames to e.v3 and
        // adds 'final' (documented batch semantics: all same-type upcasters
        // of the CURRENT type run before the walk continues).
        self::assertSame(['reshaped' => true, 'migrated' => true, 'final' => true], $upcast->payload);
    }

    public function testRenameToSameTypeTerminatesInsteadOfLooping(): void
    {
        $calls = 0;
        $registry = new EventUpcaster(new class($calls) implements UpcasterInterface {
            public function __construct(private int &$calls) {}

            #[\Override]
            public function eventTypes(): array
            {
                return ['e.a'];
            }

            #[\Override]
            public function upcast(StoredEvent $event): StoredEvent
            {
                ++$this->calls;

                return Audit301Rebuilder::rebuild($event, 'e.a', ['run' => $this->calls]);
            }
        });

        $upcast = $registry->transform($this->stored('e.a', 1, 1, []));

        self::assertSame(1, $calls, 'a same-type rename is a no-rename: one batch, then done');
        self::assertSame(['run' => 1], $upcast->payload);
    }

    public function testRealRenameCycleStillThrowsAfterMaxHops(): void
    {
        $registry = new EventUpcaster(
            new class implements UpcasterInterface {
                #[\Override]
                public function eventTypes(): array
                {
                    return ['e.a'];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return Audit301Rebuilder::rebuild($event, 'e.b', $event->payload);
                }
            },
            new class implements UpcasterInterface {
                #[\Override]
                public function eventTypes(): array
                {
                    return ['e.b'];
                }

                #[\Override]
                public function upcast(StoredEvent $event): StoredEvent
                {
                    return Audit301Rebuilder::rebuild($event, 'e.a', $event->payload);
                }
            },
        );

        $this->expectException(EventSourcingException::class);
        $this->expectExceptionMessage('rename cycle');

        $registry->transform($this->stored('e.a', 1, 1, []));
    }

    /**
     * @param array<mixed> $payload
     */
    private function stored(string $type, int $version, int $sequence, array $payload): StoredEvent
    {
        return new StoredEvent(
            eventId: str_pad((string) $version, 32, '0', \STR_PAD_LEFT),
            aggregateType: 'test.upcast',
            aggregateId: 'a-1',
            version: $version,
            globalSequence: $sequence,
            eventType: $type,
            payload: $payload,
            recordedAtUnixNano: 1_700_000_000_000_000_000,
        );
    }
}

/**
 * @internal
 */
final class Audit301Rebuilder
{
    /** @param array<mixed> $payload */
    public static function rebuild(StoredEvent $event, string $newType, array $payload): StoredEvent
    {
        return new StoredEvent(
            eventId: $event->eventId,
            aggregateType: $event->aggregateType,
            aggregateId: $event->aggregateId,
            version: $event->version,
            globalSequence: $event->globalSequence,
            eventType: $newType,
            payload: $payload,
            metadata: $event->metadata,
            recordedAtUnixNano: $event->recordedAtUnixNano,
        );
    }
}
