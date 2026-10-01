<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.34.1 — signed-domain available_at parity tests (issue #272).
 *
 * The parity contract pinned in docs/JOB-QUEUE-PARITY.md: every adapter
 * must preserve NUMERIC ordering and NUMERIC availability verdicts over
 * the entire signed 64-bit domain of availableAtUnixNano (and of
 * dequeue($nowUnixNano)). v2.32-v2.34 stored Redis negatives unpadded, so
 * the dequeue Lua's lexicographic comparisons inverted both verdicts
 * against the numeric baseline of InMemoryJobQueue/PdoJobQueue —
 * reproduced live and pinned in the matrix BEFORE this fix landed.
 *
 * Four harnesses:
 * - live: a Redis server at 127.0.0.1:6399 (the CI docker profile) — the
 *   three-adapter ordering/availability parity over the signed domain, the
 *   exact round-trip, and the corrupt-entry doctrine (liveness over
 *   preservation);
 * - pdo / in-memory: always-on sections pinning the numeric baseline the
 *   reference adapters must keep (a regression that silently narrows
 *   their acceptance domain would fail here, not just in the Redis zone);
 * - encoding: the pure order-preservation invariant over
 *   JobRowCodec::encodeNano()/decodeNano() — byte-compat with legacy
 *   v2.32-v2.34 storage, round-trip of PHP_INT_MIN, and malformed-field
 *   rejection; needs no ext-redis and no server, so it runs on every
 *   platform profile;
 * - fuzz: bounded random signed pairs asserting lexicographic order
 *   equals numeric order across the domain.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobQueueInterface;
use Zef\Framework\Job\JobRowCodec;
use Zef\Framework\Job\PdoJobQueue;
use Zef\Framework\Job\RedisJobQueueException;
use Zef\Framework\Job\RedisStreamJobQueue;

/**
 * @internal
 */
final class QueueNegativeTimestampParityTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const int PORT = 6399;

    private const string PASS = 'zef-test-secret';

    private ?\Redis $redis = null;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension not available.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis && $this->redis->isConnected()) {
            $this->redis->flushDB();
            $this->redis->close();
        }
    }

    // ------------------------------------------------------------------
    // Live: the three-adapter parity over the signed domain
    // ------------------------------------------------------------------

    /**
     * Matrix rows 1-2: the SAME shuffled signed deadline set must dequeue
     * in the SAME numeric order from all three adapters — the Redis
     * lexicographic comparison only stays correct because the encoding is
     * order-preserving over the full signed domain.
     */
    public function testSignedDomainOrderingIsIdenticalAcrossAllThreeAdapters(): void
    {
        $deadlines = [15, -20, 0, 3, -10, -1, 7, \PHP_INT_MIN, 12_345, -3];
        $expected = ['job-np-07', 'job-np-01', 'job-np-04', 'job-np-09', 'job-np-05', 'job-np-02', 'job-np-03', 'job-np-06', 'job-np-00', 'job-np-08'];

        $memory = $this->inMemorySequence($deadlines);
        $pdo = $this->pdoSequence($deadlines);
        $redis = $this->redisSequence($deadlines);

        self::assertSame($expected, $memory, 'InMemoryJobQueue orders numerically (the baseline)');
        self::assertSame($expected, $pdo, 'PdoJobQueue orders numerically via BIGINT ORDER BY');
        self::assertSame($expected, $redis, 'RedisStreamJobQueue orders numerically via the order-preserving encoding');
        self::assertSame($memory, $redis, 'matrix row 2: Redis ordering is identical to the InMemory baseline');
        self::assertSame($pdo, $redis, 'matrix row 2: Redis ordering is identical to the PDO baseline');
    }

    /**
     * Matrix row 3: the availability verdict `available_at <= now` must
     * agree numerically on all three adapters — at now = -15 exactly the
     * deadlines at -20 and -10 are due, in that order, and nothing else.
     */
    public function testNegativeAvailabilityVerdictIsIdenticalAcrossAllThreeAdapters(): void
    {
        $deadlines = [-20, -10, -5, 0, 5];

        $memory = $this->inMemoryDueSequence($deadlines, -15);
        $pdo = $this->pdoDueSequence($deadlines, -15);
        $redis = $this->redisDueSequence($deadlines, -15);

        self::assertSame(['job-np-00'], $memory, 'InMemory: only A(-20) is due at now=-15 (B(-10) is not yet due)');
        self::assertSame(['job-np-00'], $pdo, 'PDO: only A(-20) is due at now=-15 (B(-10) is not yet due)');
        self::assertSame(['job-np-00'], $redis, 'Redis: only A(-20) is due at now=-15 (pre-fix this inverted to B due, A not)');
    }

    /**
     * Matrix row 4: the signed value round-trips exactly — including the
     * domain edges the v2.32 doc claimed were "pathological": PHP_INT_MIN
     * (whose magnitude exceeds PHP_INT_MAX and must be handled as a
     * string, never a float) and PHP_INT_MAX.
     */
    public function testSignedRoundTripPreservesExactValuesLiveRedis(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'negrt');
        $queue->enqueue($this->job('job-negrt-a', -20));
        $queue->enqueue($this->job('job-negrt-b', -10));
        $queue->enqueue($this->job('job-negrt-min', \PHP_INT_MIN));
        $queue->enqueue($this->job('job-negrt-max', \PHP_INT_MAX));

        foreach ([\PHP_INT_MIN, -20, -10, \PHP_INT_MAX] as $expected) {
            $out = $queue->dequeue(\PHP_INT_MAX);
            self::assertInstanceOf(JobEnvelope::class, $out);
            self::assertSame($expected, $out->availableAtUnixNano, 'the signed deadline survives the stored representation exactly');
        }
    }

    /**
     * Matrix row 10 (corrupt-entry doctrine): a negative available_at
     * written by a pre-v2.34.1 release is stored unpadded ("-20", width
     * != 20), so the decoder treats it as a corrupt field. The claim has
     * already destroyed the entry, the hydrate throws exactly once, and
     * the queue keeps flowing — liveness over preservation.
     */
    public function testPreFixUnpaddedNegativeFailsLoudlyOnceAndQueueKeepsFlowing(): void
    {
        $redis = $this->liveRedis();
        $redis->xadd('zef:jobq:corruptneg', '*', [
            'job_id' => 'job-corrupt-01', 'job_type' => 't.corrupt', 'payload' => 'null',
            'available_at' => '-20', 'priority' => '0', 'attempt' => '1',
            'correlation_id' => '', 'trace_parent' => '', 'headers' => '{}',
        ]);
        $queue = new RedisStreamJobQueue($redis, 'corruptneg');
        $queue->enqueue($this->job('job-good-01', 0));

        try {
            $queue->dequeue(0);
            self::fail('the pre-fix unpadded negative entry must fail the hydrate loudly');
        } catch (RedisJobQueueException $e) {
            self::assertStringContainsString('corrupt available_at', $e->getMessage());
        }

        $out = $queue->dequeue(0);
        self::assertInstanceOf(JobEnvelope::class, $out);
        self::assertSame('job-good-01', $out->jobId, 'the queue keeps flowing after the corrupt claim surfaced once');
        self::assertNull($queue->dequeue(0), 'the corrupt entry was destroyed by its claim, not preserved');
    }

    // ------------------------------------------------------------------
    // In-memory / PDO: the numeric baseline (always-on)
    // ------------------------------------------------------------------

    public function testInMemoryAcceptsAndOrdersTheFullSignedDomain(): void
    {
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->job('job-im-min', \PHP_INT_MIN));
        $queue->enqueue($this->job('job-im-a', -20));
        $queue->enqueue($this->job('job-im-b', -10));
        $queue->enqueue($this->job('job-im-zero', 0));

        foreach (['job-im-min', 'job-im-a', 'job-im-b', 'job-im-zero'] as $id) {
            $out = $queue->dequeue(\PHP_INT_MAX);
            self::assertInstanceOf(JobEnvelope::class, $out);
            self::assertSame($id, $out->jobId);
        }
        self::assertNull($queue->dequeue(\PHP_INT_MAX));

        $only = new InMemoryJobQueue();
        $only->enqueue($this->job('job-im-a2', -20));
        $only->enqueue($this->job('job-im-b2', -10));
        self::assertSame('job-im-a2', $only->dequeue(-15)?->jobId, 'A(-20) is due at now=-15');
        self::assertNull($only->dequeue(-15), 'B(-10) is not yet due at now=-15');
    }

    public function testPdoAcceptsAndOrdersTheFullSignedDomain(): void
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_negpar');
        $queue->createSchema();
        $queue->enqueue($this->job('job-pdo-min', \PHP_INT_MIN));
        $queue->enqueue($this->job('job-pdo-a', -20));
        $queue->enqueue($this->job('job-pdo-b', -10));
        $queue->enqueue($this->job('job-pdo-zero', 0));

        foreach (['job-pdo-min', 'job-pdo-a', 'job-pdo-b', 'job-pdo-zero'] as $id) {
            $out = $queue->dequeue(\PHP_INT_MAX);
            self::assertInstanceOf(JobEnvelope::class, $out);
            self::assertSame($id, $out->jobId);
        }
        self::assertNull($queue->dequeue(\PHP_INT_MAX));

        $only = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_negpar2');
        $only->createSchema();
        $only->enqueue($this->job('job-pdo-a2', -20));
        $only->enqueue($this->job('job-pdo-b2', -10));
        self::assertSame('job-pdo-a2', $only->dequeue(-15)?->jobId, 'A(-20) is due at now=-15');
        self::assertNull($only->dequeue(-15), 'B(-10) is not yet due at now=-15');
    }

    // ------------------------------------------------------------------
    // The encoding invariant (reflection; no server, no ext-redis needed)
    // ------------------------------------------------------------------

    /**
     * Matrix section 3, byte-compat: non-negative deadlines encode to
     * exactly the bytes v2.32-v2.34 wrote — the fix must not touch a
     * single stored character of every realistic queue.
     */
    public function testNonNegativeEncodingIsByteIdenticalToLegacySprintf(): void
    {
        foreach ([0, 1, 42, 1_000_000, 1_700_000_000_123_456_789, 9_000_000_000_000_000_000, \PHP_INT_MAX] as $value) {
            self::assertSame(
                sprintf('%020d', $value),
                $this->encodedNano($value),
                "v={$value}: legacy byte-compat is the migration guarantee",
            );
        }
    }

    /**
     * Matrix section 3, negatives: '-' + 9's complement of the 19-digit
     * zero-padded magnitude — shape checked explicitly so a width or
     * alphabet drift fails with a readable diff, not a downstream mystery.
     */
    public function testNegativeEncodingIsMinusPlusNineteenComplementDigits(): void
    {
        self::assertSame('-9999999999999999979', $this->encodedNano(-20));
        self::assertSame('-9999999999999999989', $this->encodedNano(-10));
        self::assertSame('-9999999999999999998', $this->encodedNano(-1));

        foreach ([-1, -2, -10, -20, -1_000_000_000, -9_000_000_000_000_000_000, \PHP_INT_MIN] as $value) {
            $encoded = $this->encodedNano($value);
            self::assertSame(20, strlen($encoded), "v={$value}: total width is 20 characters");
            self::assertSame('-', $encoded[0], "v={$value}: negatives carry the leading minus");
            self::assertSame(19, strspn(substr($encoded, 1), '0123456789'), "v={$value}: 19 complement digits follow the minus");
        }
    }

    #[DataProvider('roundTripProvider')]
    public function testEncodeDecodeRoundTripsTheExactSignedValue(int $value): void
    {
        self::assertSame($value, (int) $this->decodedNano($this->encodedNano($value)), "v={$value} survives the storage round-trip");
    }

    /** @return array<string, array{0: int}> */
    public static function roundTripProvider(): array
    {
        return [
            'zero' => [0],
            'one' => [1],
            'minus-one' => [-1],
            'ten' => [10],
            'minus-ten' => [-10],
            'audit-repro-a' => [-20],
            'audit-repro-b' => [-10],
            'nano-scale' => [1_700_000_000_123_456_789],
            'nano-scale-negative' => [-1_700_000_000_123_456_789],
            'int-max' => [\PHP_INT_MAX],
            'int-min' => [\PHP_INT_MIN],
            'int-min-plus-one' => [\PHP_INT_MIN + 1],
            'int-max-minus-one' => [\PHP_INT_MAX - 1],
        ];
    }

    #[DataProvider('malformedStoredProvider')]
    public function testDecodeRejectsMalformedStoredFields(string $stored): void
    {
        $this->expectException(RedisJobQueueException::class);
        $this->expectExceptionMessage('corrupt available_at');
        $this->decodedNano($stored);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedStoredProvider(): array
    {
        return [
            'legacy unpadded negative' => ['-20'],
            'legacy short negative' => ['-2'],
            'legacy 18-digit-magnitude negative' => ['-999999999999999999'],
            'nineteen digits (width)' => ['0000000000000001000'],
            'twenty-one digits (width)' => ['000000000000000010000'],
            'twenty chars bad charset' => ['0000000000000000100a'],
            'negative twenty bad charset' => ['-000000000000000100a'],
            'signed positive prefix' => ['+0000000000000001000'],
            'empty string' => [''],
            'lone minus' => ['-'],
        ];
    }

    /**
     * Matrix section 3, the ordering invariant itself: bounded fuzz over
     * the signed domain — sign(strcmp(enc(a), enc(b))) must equal
     * sign(a <=> b) for every pair, so the Lua's lexicographic `<`, `<=`
     * and `==` behave numerically across the whole domain.
     */
    public function testLexicographicOrderEqualsNumericOrderFuzz(): void
    {
        $edges = [\PHP_INT_MIN, \PHP_INT_MIN + 1, -1_000_000_000_000_000_000, -999_999_999_999_999_999, -1_000_000_000, -1000, -22, -20, -10, -2, -1, 0, 1, 2, 10, 20, 1000, 1_000_000_000, 999_999_999_999_999_999, 1_000_000_000_000_000_000, \PHP_INT_MAX - 1, \PHP_INT_MAX];
        $values = $edges;
        for ($i = 0; $i < 250; ++$i) {
            $values[] = random_int(-\PHP_INT_MAX, \PHP_INT_MAX);
        }
        $encoded = [];
        foreach ($values as $v) {
            $encoded[$v] = $this->encodedNano($v);
        }
        foreach ($values as $a) {
            foreach ($values as $b) {
                self::assertSame(
                    $a <=> $b,
                    strcmp($encoded[$a], $encoded[$b]) <=> 0,
                    "lex order must equal numeric order for {$a} vs {$b}",
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<int> $deadlines
     *
     * @return list<string>
     */
    private function inMemorySequence(array $deadlines): array
    {
        $queue = new InMemoryJobQueue();
        foreach ($deadlines as $i => $deadline) {
            $queue->enqueue($this->job($this->negId($i), $deadline));
        }

        return $this->drainIds($queue, \PHP_INT_MAX);
    }

    /**
     * @param list<int> $deadlines
     *
     * @return list<string>
     */
    private function inMemoryDueSequence(array $deadlines, int $now): array
    {
        $queue = new InMemoryJobQueue();
        foreach ($deadlines as $i => $deadline) {
            $queue->enqueue($this->job($this->negId($i), $deadline));
        }

        return $this->drainIds($queue, $now);
    }

    /**
     * @param list<int> $deadlines
     *
     * @return list<string>
     */
    private function pdoSequence(array $deadlines): array
    {
        $queue = $this->freshPdoQueue('negpar_seq');
        foreach ($deadlines as $i => $deadline) {
            $queue->enqueue($this->job($this->negId($i), $deadline));
        }

        return $this->drainIds($queue, \PHP_INT_MAX);
    }

    /**
     * @param list<int> $deadlines
     *
     * @return list<string>
     */
    private function pdoDueSequence(array $deadlines, int $now): array
    {
        $queue = $this->freshPdoQueue('negpar_due');
        foreach ($deadlines as $i => $deadline) {
            $queue->enqueue($this->job($this->negId($i), $deadline));
        }

        return $this->drainIds($queue, $now);
    }

    /**
     * @param list<int> $deadlines
     *
     * @return list<string>
     */
    private function redisSequence(array $deadlines): array
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'negpar');
        foreach ($deadlines as $i => $deadline) {
            $queue->enqueue($this->job($this->negId($i), $deadline));
        }

        return $this->drainIds($queue, \PHP_INT_MAX);
    }

    /**
     * @param list<int> $deadlines
     *
     * @return list<string>
     */
    private function redisDueSequence(array $deadlines, int $now): array
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'negpar_due');
        foreach ($deadlines as $i => $deadline) {
            $queue->enqueue($this->job($this->negId($i), $deadline));
        }

        return $this->drainIds($queue, $now);
    }

    /** @return list<string> */
    private function drainIds(JobQueueInterface $queue, int $now): array
    {
        $ids = [];
        while (($out = $queue->dequeue($now)) instanceof JobEnvelope) {
            $ids[] = $out->jobId;
        }

        return $ids;
    }

    private function negId(int $i): string
    {
        return sprintf('job-np-%02d', $i);
    }

    private function freshPdoQueue(string $suffix): PdoJobQueue
    {
        $queue = new PdoJobQueue($this->sqliteConn(), 'zef_jobs_' . $suffix);
        $queue->createSchema();

        return $queue;
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

    /**
     * Live-server guard: builds a connected client or skips the test —
     * the documented self-skip contract (INSTALLATION.md §8) must survive
     * a dead server, same capture as RedisStreamJobQueueTest.
     */
    private function liveRedis(): \Redis
    {
        $redis = new \Redis();
        $reachable = false;

        try {
            $reachable = $redis->pconnect(self::HOST, self::PORT, 2.0) && $redis->auth(self::PASS);
        } catch (\RedisException) {
            $reachable = false;
        }
        if (!$reachable) {
            self::markTestSkipped('Redis test server not reachable.');
        }
        $redis->select(0);
        $redis->flushDB();
        $this->redis = $redis;

        return $redis;
    }

    /** @param array<string, string> $headers */
    private function job(
        string $id,
        int $availableAt = 0,
        int $priority = 0,
        int $attempt = 1,
        ?string $correlationId = null,
        ?string $traceParent = null,
        array $headers = [],
        mixed $payload = null,
    ): JobEnvelope {
        return new JobEnvelope($id, 't.' . $id, $payload, $availableAt, $priority, $attempt, $correlationId, $traceParent, $headers);
    }

    /**
     * The order-preserving encoding lives in {@see JobRowCodec} (the pure
     * static row codec); these helpers keep the encoding-invariant tests
     * reading the same way they did when the methods were private on the
     * queue.
     */
    private function encodedNano(int $value): string
    {
        return JobRowCodec::encodeNano($value);
    }

    private function decodedNano(string $stored): string
    {
        return JobRowCodec::decodeNano($stored);
    }
}
