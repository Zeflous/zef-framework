<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\EventSourcing\AggregateNotFoundException;
use Zef\Framework\EventSourcing\AggregateRoot;
use Zef\Framework\EventSourcing\ConcurrencyException;
use Zef\Framework\EventSourcing\EventGrammar;
use Zef\Framework\EventSourcing\EventJson;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\PendingEvent;
use Zef\Framework\EventSourcing\Snapshot;
use Zef\Framework\EventSourcing\StoredEvent;

/**
 * v2.19.0 — Event Sourcing Domain: value objects, grammar, aggregate root
 * mechanics (record → pending → commit, replay, snapshot codec).
 *
 * @internal
 */
final class EventSourcingDomainTest extends TestCase
{
    // -------------------------------------------------- PendingEvent

    public function testPendingEventAcceptsValidInput(): void
    {
        $event = new PendingEvent('order.placed', ['sku' => 'X1'], ['actor' => 'u1']);
        self::assertSame('order.placed', $event->eventType);
        self::assertSame(['sku' => 'X1'], $event->payload);
        self::assertSame(['actor' => 'u1'], $event->metadata);
    }

    public function testPendingEventRejectsBadGrammar(): void
    {
        try {
            new PendingEvent('');
            self::fail('empty event type must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid event type', $e->getMessage());
        }

        try {
            new PendingEvent(str_repeat('a', 192));
            self::fail('192-byte event type must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('1..191', $e->getMessage());
        }
        // 191 bytes is the inclusive maximum.
        new PendingEvent(str_repeat('a', 191));
        new PendingEvent('a.b:c-d_e');
    }

    public function testPendingEventRejectsNonEncodablePayload(): void
    {
        try {
            new PendingEvent('x', ['bad' => fopen('php://memory', 'rb')]);
            self::fail('resource payload must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('JSON-encodable', $e->getMessage());
        }

        try {
            new PendingEvent('x', ['nan' => NAN]);
            self::fail('NAN payload must be rejected');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('JSON-encodable', $e->getMessage());
        }
    }

    // -------------------------------------------------- StoredEvent

    public function testStoredEventFullValidation(): void
    {
        $event = new StoredEvent(
            eventId: str_repeat('a', 32),
            aggregateType: 'bank.account',
            aggregateId: 'acc-1',
            version: 3,
            globalSequence: 7,
            eventType: 'de',
            payload: ['a' => 1],
            metadata: ['m' => 2],
            recordedAtUnixNano: 5,
        );
        self::assertSame(3, $event->version);
        self::assertSame(7, $event->globalSequence);
    }

    public function testStoredEventFieldGuardsInOrder(): void
    {
        $ok = fn (): StoredEvent => new StoredEvent(
            eventId: str_repeat('0', 32),
            aggregateType: 't',
            aggregateId: 'i',
            version: 1,
            globalSequence: 1,
            eventType: 'e',
        );

        try {
            new StoredEvent('SHORT', 't', 'i', 1, 1, 'e');
            self::fail('31-hex id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid event id', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('A', 32), 't', 'i', 1, 1, 'e');
            self::fail('uppercase hex id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid event id', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('0', 32), '', 'i', 1, 1, 'e');
            self::fail('empty aggregate type must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('0', 32), 't', "bad\tid", 1, 1, 'e');
            self::fail('whitespace id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('0', 32), 't', 'i', 0, 1, 'e');
            self::fail('version 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('version must be >= 1 (got 0)', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('0', 32), 't', 'i', 1, 0, 'e');
            self::fail('global sequence 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('global sequence must be >= 1 (got 0)', $e->getMessage());
        }

        try {
            new StoredEvent(str_repeat('0', 32), 't', 'i', 1, 1, 'e', [], [], -1);
            self::fail('negative timestamp must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('recordedAtUnixNano must be >= 0', $e->getMessage());
        }
        $ok();
    }

    public function testStoredEventAcceptsBoundaryIdentityBytes(): void
    {
        // 128-byte aggregate id (inclusive max) and 129 (exclusive).
        $long = str_repeat('a', 128);
        new StoredEvent(str_repeat('0', 32), 't', $long, 1, 1, 'e');

        try {
            new StoredEvent(str_repeat('0', 32), 't', $long . 'a', 1, 1, 'e');
            self::fail('129-byte id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate ID', $e->getMessage());
        }
    }

    // -------------------------------------------------- Snapshot

    public function testSnapshotValidation(): void
    {
        $snapshot = new Snapshot('type', 'id', 5, ['k' => 'v'], 9);
        self::assertSame(5, $snapshot->version);

        try {
            new Snapshot('type', 'id', 0, []);
            self::fail('snapshot version 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('version must be >= 1', $e->getMessage());
        }

        try {
            new Snapshot('type', 'id', 1, ['bad' => \NAN]);
            self::fail('NAN state must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Snapshot state must be JSON-encodable', $e->getMessage());
        }
    }

    // -------------------------------------------------- OutboxEntry

    public function testOutboxEntryValidationAndPredicates(): void
    {
        $entry = new OutboxEntry(str_repeat('1', 32), 'm.t', [], [], 0, OutboxEntry::STATUS_PENDING, 0, null, 1);
        self::assertTrue($entry->isPending());
        self::assertFalse($entry->isProcessed());
        self::assertFalse($entry->isFailed());

        self::assertTrue(new OutboxEntry(str_repeat('1', 32), 'm', [], [], 2, OutboxEntry::STATUS_PROCESSED, 0, null, 0)->isProcessed());
        self::assertTrue(new OutboxEntry(str_repeat('1', 32), 'm', [], [], 3, OutboxEntry::STATUS_FAILED, 0, 'x', 0)->isFailed());

        try {
            new OutboxEntry('nope', 'm', [], [], 0, 'pending', 0, null, 0);
            self::fail('non-hex entry id must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid outbox entry id', $e->getMessage());
        }

        try {
            new OutboxEntry(str_repeat('1', 32), 'm', [], [], -1, 'pending', 0, null, 0);
            self::fail('negative attempts must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('attempts must be >= 0 (got -1)', $e->getMessage());
        }

        try {
            new OutboxEntry(str_repeat('1', 32), 'm', [], [], 0, 'zombie', 0, null, 0);
            self::fail('unknown status must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid outbox status', $e->getMessage());
        }

        try {
            new OutboxEntry(str_repeat('1', 32), 'm', [], [], 0, 'pending', -5, null, 0);
            self::fail('negative next attempt must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('nextAttemptAtUnixNano must be >= 0', $e->getMessage());
        }
    }

    // -------------------------------------------------- Exceptions

    public function testConcurrencyExceptionCarriesVersions(): void
    {
        $e = new ConcurrencyException(4, 9);
        self::assertSame(4, $e->expectedVersion());
        self::assertSame(9, $e->actualVersion());
        self::assertSame('Concurrency conflict: expected stream version 4, actual is 9.', $e->getMessage());
        self::assertInstanceOf(EventSourcingException::class, $e);
    }

    public function testAggregateNotFoundExceptionCarriesIdentity(): void
    {
        $e = new AggregateNotFoundException('App\Order', 'o-1');
        self::assertSame('App\Order', $e->aggregateClass());
        self::assertSame('o-1', $e->aggregateId());
        self::assertSame("Aggregate App\\Order 'o-1' was not found.", $e->getMessage());
    }

    // -------------------------------------------------- EventGrammar / EventJson

    public function testEventGrammarBoundaries(): void
    {
        EventGrammar::assertAggregateType(str_repeat('x', 128));
        EventGrammar::assertAggregateType('A9._:-');
        EventGrammar::assertEventType(str_repeat('x', 191));
        EventGrammar::assertEventId(str_repeat('f', 32));

        try {
            EventGrammar::assertAggregateType(str_repeat('x', 129));
            self::fail('129 bytes must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid aggregate type', $e->getMessage());
        }

        try {
            EventGrammar::assertAggregateType('sp ace');
            self::fail('space must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString("Invalid aggregate type 'sp ace'", $e->getMessage());
        }

        try {
            EventGrammar::assertEventType('bad/slash');
            self::fail('slash must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Invalid event type', $e->getMessage());
        }

        try {
            EventGrammar::assertVersion(0);
            self::fail('version 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('version must be >= 1 (got 0).', $e->getMessage());
        }

        try {
            EventGrammar::assertGlobalSequence(-1, 'seq');
            self::fail('negative sequence must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('seq must be >= 1 (got -1).', $e->getMessage());
        }

        try {
            EventGrammar::assertUnixNano(-1, 'ts');
            self::fail('negative timestamp must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('ts must be >= 0 (got -1).', $e->getMessage());
        }
        EventGrammar::assertPayload(['ok' => [1, 2.5, 'x', null, false]], 'p');
    }

    /**
     * Regresi P-15 (issue #170): float ber-fraksi nol (10.0) wajib tetap float
     * setelah round-trip encode→decode. Tanpa JSON_PRESERVE_ZERO_FRACTION,
     * json_encode menulis `10` dan replay membaca int(10) — tipe field payload
     * berubah diam-diam antara append dan replay.
     */
    public function testEventJsonRoundTripPreservesZeroFractionFloats(): void
    {
        $value = ['amount' => 10.0, 'ratio' => 0.5, 'nested' => ['score' => 3.0], 'count' => 42];
        $json = EventJson::encode($value, 'field');

        $decoded = EventJson::decode($json, 'field');

        // assertSame pins element types too: 10.0 must stay float, 42 must stay int.
        self::assertSame($value, $decoded, 'round-trip wajib identik — 10.0 tetap float, 42 tetap int');
    }

    public function testEventJsonEncodeDecodeRoundTrip(): void
    {
        $value = ['list' => [1, 2], 'map' => ['a' => 'b'], 'uni' => 'héllo'];
        $json = EventJson::encode($value, 'field');
        self::assertSame($value, EventJson::decode($json, 'field'));

        try {
            EventJson::encode(['x' => \INF], 'field');
            self::fail('INF must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('field must be JSON-encodable', $e->getMessage());
        }

        try {
            EventJson::decode('{nope', 'field');
            self::fail('broken JSON must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Corrupt field:', $e->getMessage());
        }

        try {
            EventJson::decode('"scalar"', 'field');
            self::fail('scalar JSON must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('expected a JSON object or array', $e->getMessage());
        }
    }

    // -------------------------------------------------- AggregateRoot

    public function testAggregateRecordingLifecycle(): void
    {
        $aggregate = EventSourcingTestAccount::open('a-1', 100);
        self::assertSame(1, $aggregate->version());
        self::assertSame(0, $aggregate->pendingVersion());
        self::assertSame(1, $aggregate->pendingCount());
        self::assertTrue($aggregate->hasPendingEvents());
        self::assertSame(100, $aggregate->balance());

        $aggregate->deposit(25);
        self::assertSame(2, $aggregate->version());
        self::assertSame(125, $aggregate->balance());

        $pending = $aggregate->pendingEvents();
        self::assertCount(2, $pending);
        self::assertInstanceOf(PendingEvent::class, $pending[0]);
        self::assertSame('account.opened', $pending[0]->eventType);

        $aggregate->markCommitted();
        self::assertSame(0, $aggregate->pendingCount());
        self::assertSame(2, $aggregate->pendingVersion());
        self::assertFalse($aggregate->hasPendingEvents());
        self::assertSame('a-1', $aggregate->aggregateId());
        self::assertSame('test.account', EventSourcingTestAccount::aggregateType());
    }

    public function testAggregateGrammarFailureDoesNotAdvanceVersion(): void
    {
        $aggregate = EventSourcingTestAccount::open('a-1', 10);
        $aggregate->markCommitted();
        self::assertSame(1, $aggregate->version());

        try {
            $aggregate->depositPublicly('');
            self::fail('empty event type must fail');
        } catch (EventSourcingException) {
            // expected
        }
        self::assertSame(1, $aggregate->version());
        self::assertSame(0, $aggregate->pendingCount());
        self::assertSame(10, $aggregate->balance());
    }

    public function testApplyStoredReplayAndOrderGuard(): void
    {
        $aggregate = EventSourcingTestAccount::createEmpty('a');
        $event1 = $this->stored('account.opened', 1, 1, ['initial' => 5]);
        $aggregate->applyStored($event1);
        $aggregate->applyStored($this->stored('account.deposited', 2, 2, ['amount' => 7]));
        self::assertSame(12, $aggregate->balance());
        self::assertSame(2, $aggregate->version());

        try {
            $aggregate->applyStored($this->stored('account.deposited', 2, 3, ['amount' => 7]));
            self::fail('equal version must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('expected stored version 3, got 2', $e->getMessage());
        }

        try {
            $aggregate->applyStored($this->stored('account.deposited', 1, 3, ['amount' => 7]));
            self::fail('regressed version must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('Corrupt stream', $e->getMessage());
        }
    }

    public function testSeedVersionGuard(): void
    {
        $aggregate = EventSourcingTestAccount::restoreFromSnapshot(['id' => '', 'balance' => 1], 4);
        self::assertSame(4, $aggregate->version());

        try {
            EventSourcingTestAccount::restoreFromSnapshot(['id' => '', 'balance' => 1], -1);
            self::fail('negative seed must fail');
        } catch (EventSourcingException $e) {
            self::assertSame('Snapshot version must be >= 0 (got -1).', $e->getMessage());
        }
    }

    public function testSnapshotStateCodec(): void
    {
        $aggregate = EventSourcingTestAccount::open('a-9', 3);
        $aggregate->deposit(4);
        $state = $aggregate->snapshotState();
        self::assertSame(['balance' => 7], ['balance' => $state['balance']]);
        $restored = EventSourcingTestAccount::restoreFromSnapshot($state, 2);
        self::assertSame(7, $restored->balance());
        self::assertSame('a-9', $restored->aggregateId());
    }

    public function testUnknownEventTypeOnApplySurfaces(): void
    {
        $aggregate = EventSourcingTestAccount::createEmpty('a');

        try {
            $aggregate->applyStored($this->stored('alien.event', 1, 1));
            self::fail('unknown event type must fail');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Unknown event alien.event', $e->getMessage());
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function stored(string $type, int $version, int $sequence, array $payload = []): StoredEvent
    {
        return new StoredEvent(
            eventId: str_pad((string) $version, 32, '0', \STR_PAD_LEFT),
            aggregateType: 'test.account',
            aggregateId: 'a-1',
            version: $version,
            globalSequence: $sequence,
            eventType: $type,
            payload: $payload,
        );
    }
}
