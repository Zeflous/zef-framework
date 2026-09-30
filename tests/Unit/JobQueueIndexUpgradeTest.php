<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — JobQueueSeqBackstop index-upgrade + JobRowCodec
 * narrow-cast tests.
 *
 * Re-measuring the infra-job-pdo zone at the v2.32.0 zone split (the zone
 * registry became an explicit file list when infra-job-redis was carved
 * out) surfaced five mutants the committed baseline had never seen as
 * code-drift: the two CREATE UNIQUE INDEX error-classification branches
 * (strtolower / LogicalAnd) and three narrow-cast lines in JobRowCodec.
 * These tests pin them so the re-frozen baseline starts from measured
 * kills, not from luck.
 *
 * The connection double follows the SeqCollisionConnection pattern: every
 * statement delegates to a real SQLite connection except the index DDL the
 * test wants to fail with a driver-shaped message.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\QueryException;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Job\JobRowCodec;
use Zef\Framework\Job\PdoJobQueue;

/**
 * @internal
 */
final class JobQueueIndexUpgradeTest extends TestCase
{
    /**
     * The plain CREATE UNIQUE INDEX retry classifies driver errors:
     * already-exists (any case) and duplicate-key-name are swallowed,
     * anything else rethrows loudly.
     *
     * @param string $message the driver-shaped error text
     * @param bool   $swallowed whether ensureUniqueIndex must absorb it
     */
    #[DataProvider('indexErrorProvider')]
    public function testIndexUpgradeClassifiesDriverErrors(string $message, bool $swallowed): void
    {
        $conn = new IndexFailingConnection($this->sqliteConn(), $message);
        $queue = new PdoJobQueue($conn, 'zef_job_upgrade');

        try {
            $queue->createSchema();
        } catch (QueryException $e) {
            self::assertFalse($swallowed, "an already-exists error must be absorbed, got: {$e->getMessage()}");

            return;
        }
        self::assertTrue($swallowed, 'a non-already-exists error must rethrow loudly');
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function indexErrorProvider(): array
    {
        return [
            'lowercase already exists' => ['index zef_job_upgrade_seq already exists', true],
            'uppercase already exists' => ['SQLITE ERROR: INDEX ALREADY EXISTS', true],
            'mysql duplicate key name' => ["Duplicate key name 'uq_zef_job_upgrade_seq'", true],
            'unrelated syntax error' => ['near "CREATE": syntax error', false],
        ];
    }

    // ------------------------------------------------------------------
    // JobRowCodec narrow casts
    // ------------------------------------------------------------------

    /** str() keeps scalar (non-string) row values instead of collapsing them. */
    public function testCodecStrNarrowsScalars(): void
    {
        self::assertSame('plain', JobRowCodec::str('plain'));
        self::assertSame('42', JobRowCodec::str(42), 'an int column value narrows to its string form');
        self::assertSame('1', JobRowCodec::str(true), 'bool true narrows to "1"');
        self::assertSame('', JobRowCodec::str(null), 'null collapses to the empty string');
        self::assertSame('', JobRowCodec::str([1, 2]), 'arrays collapse to the empty string');
    }

    /** hydrate() narrows non-numeric columns to 0; a zero attempt is still rejected by the envelope. */
    public function testCodecHydrateNarrowsNonNumericColumnsToZero(): void
    {
        $job = JobRowCodec::hydrate([
            'job_id' => 'job-codec-001',
            'job_type' => 't.codec',
            'payload' => 'null',
            'available_at' => 'not-a-number',
            'priority' => 'not-a-number',
            'attempt' => '3',
            'correlation_id' => null,
            'trace_parent' => null,
            'headers' => '{}',
        ]);
        self::assertSame(0, $job->availableAtUnixNano);
        self::assertSame(0, $job->priority);
        self::assertSame(3, $job->attempt);

        // A non-numeric attempt narrows to 0, which the envelope itself
        // rejects — the loud boundary, not a silent clamp.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job attempt must be positive.');
        JobRowCodec::hydrate([
            'job_id' => 'job-codec-002',
            'job_type' => 't.codec',
            'payload' => 'null',
            'available_at' => '0',
            'priority' => '0',
            'attempt' => 'not-a-number',
            'correlation_id' => null,
            'trace_parent' => null,
            'headers' => '{}',
        ]);
    }

    private function sqliteConn(): PdoConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]), $pdo);
    }
}

/**
 * Connection double: fails EVERY CREATE UNIQUE INDEX statement — the
 * IF NOT EXISTS variant with an "unsupported syntax" error (the documented
 * MySQL path that falls through to the plain retry) and the plain retry
 * with the driver-shaped message under test. Everything else delegates to
 * the real SQLite connection.
 *
 * @internal
 */
final class IndexFailingConnection implements ConnectionInterface
{
    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly string $plainIndexError,
    ) {}

    #[\Override]
    public function execute(SqlQuery $query): int
    {
        $sql = $query->sql;
        if (str_starts_with($sql, 'CREATE UNIQUE INDEX ')) {
            if (str_contains($sql, 'IF NOT EXISTS')) {
                throw new QueryException('unsupported index syntax', 0, null, $sql);
            }

            throw new QueryException($this->plainIndexError, 0, null, $sql);
        }

        return $this->inner->execute($query);
    }

    #[\Override]
    public function fetchAll(SqlQuery $query): array
    {
        return $this->inner->fetchAll($query);
    }

    #[\Override]
    public function fetchOne(SqlQuery $query): ?array
    {
        return $this->inner->fetchOne($query);
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return $this->inner->lastInsertId();
    }

    #[\Override]
    public function beginTransaction(?IsolationLevel $isolation = null): void
    {
        $this->inner->beginTransaction($isolation);
    }

    #[\Override]
    public function commit(): void
    {
        $this->inner->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    #[\Override]
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        return $this->inner->transaction($fn, $isolation);
    }

    #[\Override]
    public function transactionLevel(): int
    {
        return $this->inner->transactionLevel();
    }
}
