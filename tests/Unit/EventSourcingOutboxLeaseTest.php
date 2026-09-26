<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventRegistration;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\InMemoryOutbox;
use Zef\Framework\EventSourcing\OutboxClaimInterface;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxMessage;
use Zef\Framework\EventSourcing\OutboxRelay;
use Zef\Framework\EventSourcing\OutboxStoreInterface;
use Zef\Framework\EventSourcing\PdoOutbox;

/**
 * v2.31.0 — Outbox lease claiming: claimBatch/releaseLease on the PDO and
 * in-memory stores, schema maintenance (relay index + lease-column upgrade),
 * and the claim-based concurrent relay (no double dispatch, crash recovery).
 *
 * @internal
 */
final class EventSourcingOutboxLeaseTest extends TestCase
{
    private const int NANO = 1_700_000_000_000_000_000;

    private PdoConnection $conn;

    private PdoOutbox $outbox;

    private int $now = self::NANO;

    protected function setUp(): void
    {
        $this->now = self::NANO;
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
        $this->outbox = new PdoOutbox($this->conn, 'zef_outbox', fn (): int => $this->now);
        $this->outbox->createSchema();
    }

    // -------------------------------------------------- claimBatch (PDO)

    public function testClaimBatchIsFifoAndExclusiveAcrossOwners(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->enqueue('user.created', ['n' => $i]);
            $this->now += 1_000_000; // strictly increasing created_at — FIFO is (created_at, id)
        }

        $a = $this->outbox->claimBatch('relay-a', 2, 30);
        self::assertCount(2, $a);
        self::assertSame(['n' => 1], $a[0]->payload);
        self::assertSame(['n' => 2], $a[1]->payload);
        self::assertSame('relay-a', $a[0]->leaseOwner);
        self::assertTrue($a[0]->isLeased());
        self::assertTrue($a[0]->hasActiveLease($this->now));

        $b = $this->outbox->claimBatch('relay-b', 10, 30);
        self::assertCount(3, $b);
        self::assertSame(['n' => 3], $b[0]->payload);
        self::assertSame('relay-b', $b[0]->leaseOwner);

        self::assertSame([], $this->outbox->claimBatch('relay-c', 10, 30));
    }

    public function testClaimSkipsEntriesWithFutureNextAttempt(): void
    {
        $first = $this->enqueue('retry.later', []);
        $this->enqueue('due.now', []);
        $this->outbox->markFailed($first->id, 'boom', $this->now + 10_000_000_000);

        $claimed = $this->outbox->claimBatch('relay-a', 10, 30);
        self::assertCount(1, $claimed);
        self::assertSame('due.now', $claimed[0]->messageType);
    }

    public function testExpiredLeaseIsReclaimable(): void
    {
        $this->enqueue('crashed.relay', []);
        self::assertCount(1, $this->outbox->claimBatch('relay-a', 5, 10));
        self::assertSame([], $this->outbox->claimBatch('relay-b', 5, 10));

        $this->advance(11); // relay-a's lease has lapsed
        $reclaimed = $this->outbox->claimBatch('relay-b', 5, 10);
        self::assertCount(1, $reclaimed);
        self::assertSame('relay-b', $reclaimed[0]->leaseOwner);
    }

    public function testMarkProcessedClearsLeaseAndRemovesFromPool(): void
    {
        $entry = $this->enqueue('done', []);
        $claimed = $this->outbox->claimBatch('relay-a', 5, 30);
        $this->outbox->markProcessed($claimed[0]->id);

        self::assertSame(0, $this->outbox->countPending());
        self::assertSame([], $this->outbox->claimBatch('relay-b', 5, 30));
        self::assertSame([], $this->outbox->due(10));
        unset($entry);
    }

    public function testMarkFailedClearsLeaseAndRequeuesWithBackoff(): void
    {
        $this->enqueue('flaky', []);
        $claimed = $this->outbox->claimBatch('relay-a', 5, 30);
        $this->outbox->markFailed($claimed[0]->id, 'boom', $this->now + 2_000_000_000);

        self::assertSame([], $this->outbox->claimBatch('relay-b', 5, 30));
        $this->advance(3);

        // markFailed must have cleared the lease: the entry is visible in due()
        // with no owner, and another relay can claim it.
        $due = $this->outbox->due(5);
        self::assertCount(1, $due);
        self::assertNull($due[0]->leaseOwner, 'markFailed must clear the lease');
        self::assertSame(1, $due[0]->attempts);

        $retried = $this->outbox->claimBatch('relay-b', 5, 30);
        self::assertCount(1, $retried);
        self::assertSame(1, $retried[0]->attempts);
    }

    public function testReleaseLeaseReleasesOnlyTheOwnerEntries(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->enqueue('job', ['n' => $i]);
        }
        $this->outbox->claimBatch('relay-a', 2, 30);
        $this->outbox->claimBatch('relay-b', 2, 30);

        self::assertSame(2, $this->outbox->releaseLease('relay-a'));

        $c = $this->outbox->claimBatch('relay-c', 10, 30);
        self::assertCount(2, $c);
        self::assertSame([], $this->outbox->claimBatch('relay-c', 10, 30));
    }

    public function testClaimBatchArgumentValidation(): void
    {
        try {
            $this->outbox->claimBatch('', 5, 30);
            self::fail('empty owner must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('owner', $e->getMessage());
        }

        try {
            $this->outbox->claimBatch('relay-a', 0, 30);
            self::fail('limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('limit', $e->getMessage());
        }

        try {
            $this->outbox->claimBatch('relay-a', 5, 0);
            self::fail('leaseSeconds 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('leaseSeconds', $e->getMessage());
        }
    }

    public function testReleaseLeaseRejectsEmptyOwner(): void
    {
        $this->expectException(EventSourcingException::class);
        $this->outbox->releaseLease('');
    }

    // -------------------------------------------------- schema maintenance

    public function testCreateSchemaIsIdempotentAndMaintainsRelayIndex(): void
    {
        $this->outbox->createSchema();
        $this->outbox->createSchema();

        $indexes = $this->conn->fetchAll(SqlQuery::raw(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_zef_outbox_relay'",
        ));
        self::assertCount(1, $indexes);

        $columns = array_column(
            $this->conn->fetchAll(SqlQuery::raw('PRAGMA table_info("zef_outbox")')),
            'name',
        );
        self::assertContains('lease_owner', $columns);
        self::assertContains('lease_until', $columns);
    }

    public function testCreateSchemaUpgradesLegacyTableWithoutLeaseColumns(): void
    {
        $this->conn->execute(SqlQuery::raw(
            'CREATE TABLE "legacy_outbox" ('
            . '"id" VARCHAR(64) NOT NULL, '
            . '"message_type" VARCHAR(191) NOT NULL, '
            . '"payload" TEXT NOT NULL, '
            . '"metadata" TEXT NOT NULL, '
            . '"status" VARCHAR(16) NOT NULL, '
            . '"attempts" INT NOT NULL, '
            . '"next_attempt_at" BIGINT NOT NULL, '
            . '"last_error" TEXT NULL, '
            . '"created_at" BIGINT NOT NULL, '
            . 'CONSTRAINT "uq_legacy_outbox_id" UNIQUE ("id"))',
        ));

        $legacy = new PdoOutbox($this->conn, 'legacy_outbox', fn (): int => $this->now);
        $legacy->createSchema();
        $legacy->createSchema(); // upgrade path must stay idempotent

        $legacy->enqueue('legacy.msg', ['ok' => true]);
        $claimed = $legacy->claimBatch('relay-a', 5, 30);
        self::assertCount(1, $claimed);
        self::assertSame('relay-a', $claimed[0]->leaseOwner);
    }

    // -------------------------------------------------- claim-based relay

    public function testRelayLeasedPreventsDoubleDispatchAcrossRelays(): void
    {
        $store = new InMemoryOutbox(fn (): int => $this->now);
        $bus = new RecordingBus();
        for ($i = 1; $i <= 4; ++$i) {
            $store->enqueue('evt', ['n' => $i]);
        }

        $relayA = new OutboxRelay($store, $bus, fn (): int => $this->now);
        $relayB = new OutboxRelay($store, $bus, fn (): int => $this->now);

        $processedA = $relayA->relayLeased(2, 30);
        $processedB = $relayB->relayLeased(10, 30);

        self::assertSame(2, $processedA);
        self::assertSame(2, $processedB);
        self::assertCount(4, $bus->dispatched);
        $ids = array_map(static fn (OutboxMessage $m): string => $m->entry->id, $bus->dispatched);
        self::assertCount(4, array_unique($ids), 'every entry dispatched exactly once');
        self::assertSame([], $store->claimBatch('relay-c', 10, 30, $this->now));
    }

    public function testRelayLeasedRecoversAfterCrashedRelayLeaseExpiry(): void
    {
        $store = new InMemoryOutbox(fn (): int => $this->now);
        $bus = new RecordingBus();
        $entry = $store->enqueue('crash.me', []);

        // Simulate a worker that claimed the entry and died before dispatch.
        self::assertCount(1, $store->claimBatch('ghost-relay', 5, 10));

        $relay = new OutboxRelay($store, $bus, fn (): int => $this->now);
        self::assertSame(0, $relay->relayLeased(5, 30, 'live-relay'));
        self::assertCount(0, $bus->dispatched);

        $this->advance(11); // ghost lease lapses
        self::assertSame(1, $relay->relayLeased(5, 30, 'live-relay'));
        self::assertCount(1, $bus->dispatched);
        self::assertSame($entry->id, $bus->dispatched[0]->entry->id);
    }

    public function testRelayLeasedFailureRecordsBackoffAndClearsLease(): void
    {
        $store = new InMemoryOutbox(fn (): int => $this->now);
        $bus = new RecordingBus();
        $store->enqueue('failing', []);

        $relay = new OutboxRelay($store, $bus, fn (): int => $this->now, maxAttempts: 3);
        $bus->fail = true;
        self::assertSame(0, $relay->relayLeased(5, 30));
        $failureNow = $this->now;

        $this->advance(2); // the recorded backoff must have passed before due()
        [$entry] = $store->due(5);
        self::assertSame(1, $entry->attempts);
        self::assertSame(OutboxEntry::STATUS_PENDING, $entry->status);
        self::assertNull($entry->leaseOwner, 'markFailed must clear the lease');
        self::assertSame('recording-bus-failure', $entry->lastError);
        self::assertSame($failureNow + 1_000_000_000, $entry->nextAttemptAtUnixNano, 'attempt 1 backoff = base (1s)');
    }

    public function testRelayLeasedDeadLettersAfterMaxAttempts(): void
    {
        $store = new InMemoryOutbox(fn (): int => $this->now);
        $bus = new RecordingBus();
        $store->enqueue('doomed', []);

        $relay = new OutboxRelay($store, $bus, fn (): int => $this->now, maxAttempts: 1);
        $bus->fail = true;
        self::assertSame(0, $relay->relayLeased(5, 30));

        $dead = $relay->deadLetters();
        self::assertCount(1, $dead);
        self::assertSame(OutboxEntry::STATUS_FAILED, $dead[0]->status);
        self::assertNull($dead[0]->leaseOwner);
    }

    public function testRelayLeasedRequiresClaimingStore(): void
    {
        $store = new class implements OutboxStoreInterface {
            #[\Override]
            public function enqueue(string $messageType, array $payload, array $metadata = []): OutboxEntry
            {
                throw new EventSourcingException('not supported');
            }

            #[\Override]
            public function due(int $limit, ?int $nowUnixNano = null): array
            {
                return [];
            }

            #[\Override]
            public function markProcessed(string $id): void
            {
                throw new EventSourcingException('not supported');
            }

            #[\Override]
            public function markFailed(string $id, string $error, int $retryAtUnixNano): void
            {
                throw new EventSourcingException('not supported');
            }

            #[\Override]
            public function markDead(string $id, string $error): void
            {
                throw new EventSourcingException('not supported');
            }

            #[\Override]
            public function failed(int $limit): array
            {
                return [];
            }

            #[\Override]
            public function requeue(string $id, ?int $nextAttemptAtUnixNano = null): OutboxEntry
            {
                throw new EventSourcingException('not supported');
            }

            #[\Override]
            public function countPending(): int
            {
                return 0;
            }
        };
        $relay = new OutboxRelay($store, new RecordingBus(), fn (): int => $this->now);

        try {
            $relay->relayLeased(5, 30);
            self::fail('relayLeased() on a non-claiming store must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('OutboxClaimInterface', $e->getMessage());
        }

        try {
            $relay->releaseLease();
            self::fail('releaseLease() on a non-claiming store must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('OutboxClaimInterface', $e->getMessage());
        }
    }

    public function testRelayLeasedStableOwnerTokenAndExplicitOverride(): void
    {
        $store = new InMemoryOutbox(fn (): int => $this->now);
        $bus = new RecordingBus();
        $store->enqueue('owned', []);

        $relay = new OutboxRelay($store, $bus, fn (): int => $this->now);
        self::assertSame($relay->owner(), $relay->owner(), 'token is stable per relay instance');

        // Explicit owner override: the claim goes out under 'explicit-owner'.
        self::assertSame(1, $relay->relayLeased(5, 30, 'explicit-owner'));
        self::assertSame(0, $relay->releaseLease('explicit-owner'), 'markProcessed already cleared that lease');

        // Default token: claim under the relay's own token, release via releaseLease().
        $store->enqueue('held', []);
        self::assertCount(1, $store->claimBatch($relay->owner(), 5, 30));
        self::assertSame(1, $relay->releaseLease());
        self::assertSame(0, $relay->releaseLease());
    }

    public function testRelayLeasedArgumentValidation(): void
    {
        $relay = new OutboxRelay(new InMemoryOutbox(fn (): int => $this->now), new RecordingBus(), fn (): int => $this->now);

        try {
            $relay->relayLeased(0, 30);
            self::fail('limit 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('limit', $e->getMessage());
        }

        try {
            $relay->relayLeased(5, 0);
            self::fail('leaseSeconds 0 must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('leaseSeconds', $e->getMessage());
        }
    }

    // -------------------------------------------------- cross-connection exclusivity

    public function testClaimedEntriesAreDisjointAcrossConnectionsOnOneDatabase(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zef-outbox-lease-');
        try {
            $pdoA = new \PDO('sqlite:' . $file);
            $pdoA->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdoB = new \PDO('sqlite:' . $file);
            $pdoB->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $connA = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => $file]), $pdoA);
            $connB = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => $file]), $pdoB);
            $outboxA = new PdoOutbox($connA, 'shared_outbox', fn (): int => $this->now);
            $outboxB = new PdoOutbox($connB, 'shared_outbox', fn (): int => $this->now);
            $outboxA->createSchema();

            for ($i = 1; $i <= 4; ++$i) {
                $outboxA->enqueue('shared', ['n' => $i]);
            }

            $a = $outboxA->claimBatch('relay-a', 2, 60);
            $b = $outboxB->claimBatch('relay-b', 4, 60);

            self::assertCount(2, $a);
            self::assertCount(2, $b);
            $idsA = array_map(static fn (OutboxEntry $e): string => $e->id, $a);
            $idsB = array_map(static fn (OutboxEntry $e): string => $e->id, $b);
            self::assertSame([], array_values(array_intersect($idsA, $idsB)), 'no entry may be claimed by both relays');
        } finally {
            @unlink($file);
        }
    }

    // -------------------------------------------------- OutboxEntry lease V.O.

    public function testOutboxEntryLeaseMetadataIsSetAsAPair(): void
    {
        try {
            new OutboxEntry(
                id: str_repeat('a', 32),
                messageType: 'evt',
                payload: [],
                metadata: [],
                attempts: 0,
                status: OutboxEntry::STATUS_PENDING,
                nextAttemptAtUnixNano: self::NANO,
                lastError: null,
                createdAtUnixNano: self::NANO,
                leaseOwner: 'relay-a',
                leaseUntilUnixNano: null,
            );
            self::fail('partial lease metadata must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('pair', $e->getMessage());
        }

        try {
            new OutboxEntry(
                id: str_repeat('a', 32),
                messageType: 'evt',
                payload: [],
                metadata: [],
                attempts: 0,
                status: OutboxEntry::STATUS_PENDING,
                nextAttemptAtUnixNano: self::NANO,
                lastError: null,
                createdAtUnixNano: self::NANO,
                leaseOwner: str_repeat('x', 65),
                leaseUntilUnixNano: self::NANO,
            );
            self::fail('65-char owner must fail');
        } catch (EventSourcingException $e) {
            self::assertStringContainsString('1..64', $e->getMessage());
        }
    }

    public function testOutboxEntryLeasePredicates(): void
    {
        $leased = new OutboxEntry(
            id: str_repeat('a', 32),
            messageType: 'evt',
            payload: [],
            metadata: [],
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: self::NANO,
            lastError: null,
            createdAtUnixNano: self::NANO,
            leaseOwner: 'relay-a',
            leaseUntilUnixNano: self::NANO + 100,
        );
        self::assertTrue($leased->isLeased());
        self::assertTrue($leased->hasActiveLease(self::NANO + 99));
        self::assertFalse($leased->hasActiveLease(self::NANO + 100), 'lease boundary is inclusive-expiry');
        self::assertFalse($leased->hasActiveLease(self::NANO + 101));

        $bare = new OutboxEntry(
            id: str_repeat('b', 32),
            messageType: 'evt',
            payload: [],
            metadata: [],
            attempts: 0,
            status: OutboxEntry::STATUS_PENDING,
            nextAttemptAtUnixNano: self::NANO,
            lastError: null,
            createdAtUnixNano: self::NANO,
        );
        self::assertFalse($bare->isLeased());
        self::assertFalse($bare->hasActiveLease(self::NANO));
    }

    // -------------------------------------------------- helpers

    /**
     * @param array<mixed> $payload
     */
    private function enqueue(string $messageType, array $payload): OutboxEntry
    {
        return $this->outbox->enqueue($messageType, $payload);
    }

    private function advance(int $seconds): void
    {
        $this->now += $seconds * 1_000_000_000;
    }
}

/**
 * Test double for {@see EventBusInterface} that records OutboxMessage
 * dispatches and always fails — the failure signal drives the retry tests.
 *
 * @internal
 */
final class RecordingBus implements EventBusInterface
{
    /** @var list<OutboxMessage> */
    public array $dispatched = [];

    public bool $fail = false;

    #[\Override]
    public function dispatch(object $event): object
    {
        if ($event instanceof OutboxMessage) {
            if ($this->fail) {
                throw new EventSourcingException('recording-bus-failure');
            }
            $this->dispatched[] = $event;
        }

        return $event;
    }

    #[\Override]
    public function listen(string $eventClass, callable $listener, int $priority = 0): void {}

    #[\Override]
    public function subscribe(EventSubscriberInterface $subscriber): void {}

    #[\Override]
    public function dispatchWithContext(object $event, EventContext $context): object
    {
        return $this->dispatch($event);
    }

    /** @return list<EventRegistration> */
    #[\Override]
    public function registrations(): array
    {
        return [];
    }

    #[\Override]
    public function freeze(): void {}
}
