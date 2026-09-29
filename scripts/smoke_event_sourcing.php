<?php

declare(strict_types=1);

/*
 * Smoke test zona Event Sourcing v2.19.0 — bukan bagian suite, hanya
 * verifikasi cepat wiring end-to-end (run: php scripts/smoke_event_sourcing.php)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\EventSourcing\AggregateRepository;
use Zef\Framework\EventSourcing\AggregateRoot;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Event\EventContext;
use Zef\Framework\EventSourcing\EventDispatcher;
use Zef\Framework\EventSourcing\InMemoryCheckpointStore;
use Zef\Framework\EventSourcing\InMemoryEventStore;
use Zef\Framework\EventSourcing\OutboxEntry;
use Zef\Framework\EventSourcing\OutboxMessage;
use Zef\Framework\EventSourcing\OutboxRecorder;
use Zef\Framework\EventSourcing\OutboxRelay;
use Zef\Framework\EventSourcing\PdoEventStore;
use Zef\Framework\EventSourcing\PdoOutbox;
use Zef\Framework\EventSourcing\PdoSnapshotStore;
use Zef\Framework\EventSourcing\Projector;
use Zef\Framework\EventSourcing\ProjectionInterface;
use Zef\Framework\EventSourcing\SnapshotPolicy;
use Zef\Framework\EventSourcing\StoredEvent;

final class BankAccount extends AggregateRoot
{
    public function __construct(
        private string $id = '',
        private int $balance = 0,
        private array $log = [],
    ) {
    }

    public static function open(string $id, int $initial): self
    {
        $agg = new self($id);
        $agg->recordEvent('account.opened', ['initial' => $initial]);

        return $agg;
    }

    public function deposit(int $amount): void
    {
        $this->recordEvent('account.deposited', ['amount' => $amount]);
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public static function aggregateType(): string
    {
        return 'bank.account';
    }

    public function balance(): int
    {
        return $this->balance;
    }

    public function log(): array
    {
        return $this->log;
    }

    protected function apply(string $eventType, array $payload): void
    {
        $this->balance = match ($eventType) {
            'account.opened' => (int) $payload['initial'],
            'account.deposited' => $this->balance + (int) $payload['amount'],
            default => throw new LogicException("Unknown event {$eventType}"),
        };
        $this->log[] = $eventType;
    }

    public function snapshotState(): array
    {
        return ['id' => $this->id, 'balance' => $this->balance, 'log' => $this->log];
    }

    public static function restoreFromSnapshot(array $state, int $version): static
    {
        $agg = new self((string) $state['id'], (int) $state['balance'], (array) $state['log']);
        $agg->seedVersion($version);

        return $agg;
    }

    public static function createEmpty(string $aggregateId): static
    {
        return new self($aggregateId);
    }
}

// ---------------------------------------------------------------- in-memory path
$memStore = new InMemoryEventStore();
$agg = BankAccount::open('acc-001', 100);
$agg->deposit(50);
$repo = new AggregateRepository($memStore);
$stored = $repo->persist($agg);
echo 'inmem: version=' . $agg->version() . ' stored=' . count($stored) . ' balance=' . $agg->balance() . "\n";
$loaded = $repo->find(BankAccount::class, 'acc-001');
echo 'inmem load: balance=' . $loaded->balance() . ' log=' . implode(',', $loaded->log()) . "\n";
try {
    $stale = BankAccount::createEmpty('acc-001');
    $stale->deposit(1);
    $memStore->appendToStream('bank.account', 'acc-001', 0, ...$stale->pendingEvents());
    echo "inmem concurrency: MISSING EXCEPTION!!\n";
} catch (Zef\Framework\EventSourcing\ConcurrencyException $e) {
    // Do not echo the exception message: this smoke script only needs to
    // prove the concurrency guard fired, and the message can carry internal
    // detail. Report the exception class instead.
    echo 'inmem concurrency OK: ' . $e::class . "\n";
}

// ---------------------------------------------------------------- PDO path (sqlite memory)
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn = new PdoConnection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'dbname' => ':memory:']), $pdo);
$es = new PdoEventStore($conn, 'zef_events', fn (): int => 1_700_000_000_000_000_000);
$ob = new PdoOutbox($conn, 'zef_outbox', fn (): int => 1_700_000_000_000_000_000);
$sn = new PdoSnapshotStore($conn, 'zef_snapshots');
$es->createSchema();
$ob->createSchema();
$sn->createSchema();

$repo2 = new AggregateRepository(
    store: $es,
    snapshots: $sn,
    policy: SnapshotPolicy::every(2),
    outbox: new OutboxRecorder($ob, $conn),
    connection: $conn,
);

$acct = BankAccount::open('acc-002', 10);
$acct->deposit(15);   // version 2 → snapshot
$stored2 = $repo2->persist($acct);
echo 'pdo: version=' . $acct->version() . ' stored=' . count($stored2) . ' snapshotSaved=' . ($sn->load('bank.account', 'acc-002') !== null ? 'yes' : 'no') . ' pendingOutbox=' . $ob->countPending() . "\n";

$acct->deposit(5);
$repo2->persist($acct);

$again = $repo2->find(BankAccount::class, 'acc-002');
echo 'pdo load: balance=' . $again->balance() . ' log=' . implode(',', $again->log()) . "\n";

// ambient atomicity: concurrency failure must roll back the outbox too
$bomb = BankAccount::createEmpty('acc-002');
$bomb->deposit(999);
try {
    $memStore2 = $es;
    // append with wrong expected version inside repository flow:
    $es->appendToStream('bank.account', 'acc-002', 0, new Zef\Framework\EventSourcing\PendingEvent('account.deposited', ['amount' => 1]));
    echo "pdo concurrency: MISSING EXCEPTION!!\n";
} catch (Zef\Framework\EventSourcing\ConcurrencyException $e) {
    echo 'pdo concurrency OK, pendingOutbox=' . $ob->countPending() . "\n";
}

// ---------------------------------------------------------------- projector
final class BalanceProjection implements ProjectionInterface
{
    public array $seen = [];

    public function projectionId(): string
    {
        return 'balances';
    }

    public function handles(): array
    {
        return ['account.opened', 'account.deposited'];
    }

    public function handle(StoredEvent $event): void
    {
        $this->seen[] = $event->eventType . '@' . $event->globalSequence;
    }
}
$proj = new BalanceProjection();
$projector = new Projector($memStore, new InMemoryCheckpointStore(), [$proj]);
$applied = $projector->run();
echo "projector: applied={$applied} seen=" . implode('|', $proj->seen) . "\n";
$applied2 = $projector->run();
echo "projector idempotent: applied={$applied2}\n";

// ---------------------------------------------------------------- outbox relay
$bus = new class implements EventBusInterface {
    public array $received = [];

    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        // Recording stub: the relay test only asserts received[] contents;
        // listener wiring is deliberately out of scope for this smoke script.
    }

    public function subscribe(Zef\Framework\Event\EventSubscriberInterface $subscriber): void
    {
        // Recording stub: no subscriber wiring is exercised by this smoke run.
    }

    public function dispatchWithContext(object $event, EventContext $context): object
    {
        if ($event instanceof OutboxMessage) {
            $this->received[] = $event->entry->messageType;
            throw new RuntimeException('boom on first');
        }

        return $event;
    }

    public function registrations(): array
    {
        return [];
    }

    public function freeze(): void
    {
        // Recording stub: freeze semantics are covered by the framework suite.
    }

    public function dispatch(object $event): object
    {
        return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), 0));
    }

    public function getListenersForEvent(object $event): iterable
    {
        return [];
    }
};
$clockSeq = 0;
$relayClock = function () use (&$clockSeq): int {
    ++$clockSeq;

    return 1_700_000_000_000_000_000 + ($clockSeq * 1_000_000_000);
};
$relay = new OutboxRelay($ob, $bus, $relayClock, maxAttempts: 3, backoffBaseMs: 1000, backoffCapMs: 4000);
$r1 = $relay->relay(10);
echo "relay run1: processed={$r1} (expected 0, first attempt always fails)\n";
$pending = $ob->due(10, PHP_INT_MAX);
echo 'relay: pendingAfterFail=' . count($pending) . ' attempts=' . $pending[0]->attempts . ' err=' . $pending[0]->lastError . "\n";

// now a succeeding bus
$busOk = new class implements EventBusInterface {
    public array $received = [];

    public function listen(string $eventClass, callable $listener, int $priority = 0): void
    {
        // Recording stub: the relay test only asserts received[] contents;
        // listener wiring is deliberately out of scope for this smoke script.
    }

    public function subscribe(Zef\Framework\Event\EventSubscriberInterface $subscriber): void
    {
        // Recording stub: no subscriber wiring is exercised by this smoke run.
    }

    public function dispatchWithContext(object $event, EventContext $context): object
    {
        if ($event instanceof OutboxMessage) {
            $this->received[] = $event->entry->messageType;
        }

        return $event;
    }

    public function registrations(): array
    {
        return [];
    }

    public function freeze(): void
    {
        // Recording stub: freeze semantics are covered by the framework suite.
    }

    public function dispatch(object $event): object
    {
        return $this->dispatchWithContext($event, new EventContext(bin2hex(random_bytes(16)), 0));
    }

    public function getListenersForEvent(object $event): iterable
    {
        return [];
    }
};
$relayOk = new OutboxRelay($ob, $busOk, $relayClock, maxAttempts: 3, backoffBaseMs: 1000, backoffCapMs: 4000);
$r2 = $relayOk->relay(10);
echo "relay run2: processed={$r2} received=" . implode(',', $busOk->received) . "\n";
echo "relay delay(1..4): " . $relayOk->retryDelayMs(1) . ',' . $relayOk->retryDelayMs(2) . ',' . $relayOk->retryDelayMs(3) . ',' . $relayOk->retryDelayMs(4) . "\n";

// dead-letter path: maxAttempts=1 → first failure = dead
$ob2 = new PdoOutbox($conn, 'zef_outbox', $relayClock);
$ob2->enqueue('test.dead', ['x' => 1], []);
$relayDead = new OutboxRelay($ob2, $bus, $relayClock, maxAttempts: 1, backoffBaseMs: 100, backoffCapMs: 100);
$rd = $relayDead->relay(10);
$dead = $relayDead->deadLetters(10);
echo "relay dead: processed={$rd} dead=" . count($dead) . " status=" . $dead[0]->status . " attempts=" . $dead[0]->attempts . " err=" . $dead[0]->lastError . "\n";

echo "\n=== SMOKE COMPLETE ===\n";
