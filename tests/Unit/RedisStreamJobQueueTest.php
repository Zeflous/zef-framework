<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.32.0 — RedisStreamJobQueue tests.
 *
 * Two harnesses, both gated on ext-redis (the hermetic fake extends \Redis,
 * so the class declaration itself must stay outside the guard):
 *
 * - live: a Redis server at 127.0.0.1:6399 with password 'zef-test-secret'
 *   (same profile as RedisStoreTest / CI's docker redis:7-alpine) exercises
 *   the Lua scripts for real — ordering parity, atomic claim, duplicate
 *   backstop, capacity, corrupt-payload liveness;
 * - fake: a \Redis subclass returning scheduled eval/xLen/xRange results
 *   exercises the PHP-side mapping and result-classification branches with
 *   zero network (the F8 fake-Redis pattern, mutation-relevant because
 *   those branches otherwise only run against a live server).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobExecutionException;
use Zef\Framework\Job\RedisJobQueueException;
use Zef\Framework\Job\RedisStreamJobQueue;

/**
 * @internal
 */
final class RedisStreamJobQueueTest extends TestCase
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
    // Live: round-trip and ordering parity with PdoJobQueue
    // ------------------------------------------------------------------

    public function testRoundTripPreservesEveryEnvelopeField(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'rt');
        $in = $this->job('job-rt-0001', 1_700_000_000_123_456_789, 7, 3, 'corr-abc12345', '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01', ['x-header' => 'v', 'n' => '2'], ['deep' => ['list' => [1, 2, 3], 'flag' => true, 'zero' => 0.0]]);
        $queue->enqueue($in);

        $out = $queue->dequeue(2_000_000_000_000_000_000);
        self::assertInstanceOf(JobEnvelope::class, $out);
        self::assertSame($in->jobId, $out->jobId);
        self::assertSame($in->jobType, $out->jobType);
        self::assertSame($in->availableAtUnixNano, $out->availableAtUnixNano, 'nano timestamp survives the Lua double via 20-digit padding');
        self::assertSame($in->priority, $out->priority);
        self::assertSame($in->attempt, $out->attempt);
        self::assertSame($in->correlationId, $out->correlationId);
        self::assertSame($in->traceParent, $out->traceParent);
        self::assertSame($in->headers, $out->headers);
        self::assertSame($in->payload, $out->payload, 'mixed payload JSON round-trips (zero-fraction preserved)');
    }

    public function testNullOptionalsRoundTripViaEmptyStringSentinels(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'nulls');
        $queue->enqueue($this->job('job-null-01', 0, 0, 1));
        $out = $queue->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $out);
        self::assertNull($out->correlationId);
        self::assertNull($out->traceParent);
        self::assertSame([], $out->headers);
        self::assertNull($out->payload);
    }

    public function testSamePriorityDequeuesInEnqueueOrder(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'fifo');
        foreach (['job-fifo-01', 'job-fifo-02', 'job-fifo-03'] as $id) {
            $queue->enqueue($this->job($id));
        }

        foreach (['job-fifo-01', 'job-fifo-02', 'job-fifo-03'] as $id) {
            $out = $queue->dequeue(1);
            self::assertInstanceOf(JobEnvelope::class, $out);
            self::assertSame($id, $out->jobId);
        }
    }

    public function testHigherPriorityWinsOverEnqueueOrder(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'prio');
        $queue->enqueue($this->job('job-prio-lo1'));
        $queue->enqueue($this->job('job-prio-hi', 0, 5));
        $queue->enqueue($this->job('job-prio-lo2'));

        foreach (['job-prio-hi', 'job-prio-lo1', 'job-prio-lo2'] as $id) {
            $out = $queue->dequeue(1);
            self::assertInstanceOf(JobEnvelope::class, $out);
            self::assertSame($id, $out->jobId, 'priority DESC beats seq ASC (PdoJobQueue parity)');
        }
    }

    public function testEqualPriorityEarliestAvailabilityWins(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'avail');
        $queue->enqueue($this->job('job-avail-late', 5_000_000_000));
        $queue->enqueue($this->job('job-avail-early', 1_000_000_000));

        self::assertSame('job-avail-early', $queue->dequeue(9_000_000_000)?->jobId);
    }

    public function testNotYetDueJobIsNotClaimed(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'delay');
        $queue->enqueue($this->job('job-delay-01', 10_000_000_000_000));
        $queue->enqueue($this->job('job-delay-02', 1_000));

        // now=5000: only the second job (available at 1000) is due.
        $due = $queue->dequeue(5_000);
        self::assertInstanceOf(JobEnvelope::class, $due);
        self::assertSame('job-delay-02', $due->jobId);
        self::assertNull($queue->dequeue(5_000), 'delayed job stays queued until its deadline');
        $late = $queue->dequeue(10_000_000_000_000);
        self::assertInstanceOf(JobEnvelope::class, $late);
        self::assertSame('job-delay-01', $late->jobId);
    }

    public function testClaimIsDestructiveAndIdempotentPerJob(): void
    {
        $redis = $this->liveRedis();
        $queue = new RedisStreamJobQueue($redis, 'claim');
        $queue->enqueue($this->job('job-claim-01'));
        $queue->enqueue($this->job('job-claim-02'));

        self::assertSame(2, $queue->size());
        $first = $queue->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $first);
        self::assertSame('job-claim-01', $first->jobId);
        self::assertSame(1, $queue->size(), 'XDEL removed the claimed entry');
        self::assertSame(1, $redis->scard('zef:jobq:claim:ids'), 'live-id set shrank with the claim');
        $second = $queue->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $second);
        self::assertSame('job-claim-02', $second->jobId);
        self::assertNull($queue->dequeue(1));
        self::assertSame(0, $queue->size());
    }

    public function testDuplicateJobIdIsRejectedLoudly(): void
    {
        $redis = $this->liveRedis();
        $queue = new RedisStreamJobQueue($redis, 'dup');
        $queue->enqueue($this->job('job-dup-0001'));
        $queue->enqueue($this->job('job-dup-0002'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Job 'job-dup-0001' is already queued.");
        $queue->enqueue($this->job('job-dup-0001'));
    }

    public function testReEnqueueAfterClaimIsAllowed(): void
    {
        $redis = $this->liveRedis();
        $queue = new RedisStreamJobQueue($redis, 'reclaim');
        $queue->enqueue($this->job('job-retry-01'));
        self::assertNotNull($queue->dequeue(1));

        // The retry path re-enqueues the same id after the claim removed it
        // from the live-id set — the duplicate backstop must not fire.
        $queue->enqueue($this->job('job-retry-01', 0, 0, 2));
        $out = $queue->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $out);
        self::assertSame(2, $out->attempt, 'second delivery carries the bumped attempt');
    }

    public function testCapacityGuardThrowsOverflow(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'cap', null, 2);
        $queue->enqueue($this->job('job-cap-0001'));
        $queue->enqueue($this->job('job-cap-0002'));

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('Job queue capacity exceeded.');
        $queue->enqueue($this->job('job-cap-0003'));
    }

    /** Capacity 1 is the legal boundary (guard is < 1, not <= 1). */
    public function testCapacityOneIsTheLegalBoundary(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'cap1', null, 1);
        $queue->enqueue($this->job('job-cap1-001'));
        self::assertSame(1, $queue->size());

        $this->expectException(\OverflowException::class);
        $queue->enqueue($this->job('job-cap1-002'));
    }

    public function testConstructorGuards(): void
    {
        $redis = new \Redis();

        foreach (['', 'bad name', 'x' . str_repeat('a', 64), 'naïve', 'col:on'] as $name) {
            try {
                new RedisStreamJobQueue($redis, $name);
                self::fail("queue name '{$name}' must be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Queue name must match [A-Za-z0-9._-]{1,64}.', $e->getMessage());
            }
        }
        foreach ([0, -1] as $max) {
            try {
                new RedisStreamJobQueue($redis, 'ok-name', null, $max);
                self::fail("capacity {$max} must be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Job queue capacity must be positive.', $e->getMessage());
            }
        }
        // Boundary sizes are legal.
        self::assertNotNull(new RedisStreamJobQueue($redis, 'a')); // @phpstan-ignore-line
        self::assertNotNull(new RedisStreamJobQueue($redis, str_repeat('a', 64))); // @phpstan-ignore-line
    }

    public function testInvalidJobIdFailsAtStorageBoundary(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'badid');

        $this->expectException(\InvalidArgumentException::class);
        $queue->enqueue(new JobEnvelope('short', 't.x', null, 0));
    }

    public function testPeekIsReadOnly(): void
    {
        $redis = $this->liveRedis();
        $queue = new RedisStreamJobQueue($redis, 'peek');
        $queue->enqueue($this->job('job-peek-01'));
        $queue->enqueue($this->job('job-peek-02'));

        $seen = $queue->peek(1);
        self::assertCount(1, $seen, 'peek honours the entry budget');
        self::assertSame('job-peek-01', $seen[0]->jobId, 'oldest-first');
        self::assertSame(2, $queue->size(), 'peek claims nothing');
        self::assertSame(2, $redis->scard('zef:jobq:peek:ids'), 'peek does not touch the live-id set');
        self::assertSame('job-peek-01', $queue->dequeue(1)?->jobId, 'the peeked entry is still claimable');
    }

    public function testPeekBudgetGuard(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'peekg');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Peek budget must be positive.');
        $queue->peek(0);
    }

    public function testNamesIsolateStreams(): void
    {
        $redis = $this->liveRedis();
        $a = new RedisStreamJobQueue($redis, 'one');
        $b = new RedisStreamJobQueue($redis, 'two');
        $a->enqueue($this->job('job-iso-0001'));
        $b->enqueue($this->job('job-iso-0002'));

        self::assertSame(1, $a->size());
        self::assertSame(1, $b->size());
        $fromB = $b->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $fromB);
        self::assertSame('job-iso-0002', $fromB->jobId);
        self::assertSame(1, $a->size(), 'claiming from queue two leaves queue one intact');
    }

    /**
     * Liveness-over-preservation (documented divergence from PdoJobQueue):
     * a tampered payload is destroyed by the claim, the hydrate failure
     * surfaces exactly once, and the queue keeps flowing.
     */
    public function testCorruptPayloadIsDestroyedAndSurfacesOnce(): void
    {
        $redis = $this->liveRedis();
        $queue = new RedisStreamJobQueue($redis, 'corrupt');
        $queue->enqueue($this->job('job-good-01'));

        // Tamper: an entry whose payload is not JSON, written through the
        // raw client the way an operator or a bug would.
        $redis->eval(
            <<<'LUA'
                redis.call('SADD', KEYS[2], 'job-bad-0001')
                redis.call('XADD', KEYS[1], '*',
                    'job_id', 'job-bad-0001', 'job_type', 't.bad', 'payload', '{not json',
                    'available_at', '00000000000000000000', 'priority', '9', 'attempt', '1',
                    'correlation_id', '', 'trace_parent', '', 'headers', '{}')
                LUA,
            ['zef:jobq:corrupt', 'zef:jobq:corrupt:ids'],
            2,
        );

        try {
            $queue->dequeue(1);
            self::fail('corrupt payload must surface loudly');
        } catch (JobExecutionException) {
            // expected: corruptPayload from JobRowCodec::hydrate
        }
        self::assertSame(1, $queue->size(), 'the corrupt entry was destroyed by its claim');
        $good = $queue->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $good, 'the queue keeps flowing onto the good entry');
    }

    public function testDequeueOnEmptyQueueReturnsNull(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'empty');

        self::assertNull($queue->dequeue());
        self::assertSame(0, $queue->size());
        self::assertSame([], $queue->peek());
    }

    // ------------------------------------------------------------------
    // Fake: hermetic result-classification branches (no server needed)
    // ------------------------------------------------------------------

    /**
     * The Lua enqueue receives every numeric argument as an exact STRING —
     * capacity, the zero-padded deadline, priority and attempt. The types
     * matter: a CastString dropped anywhere would hand Lua an int where the
     * padded-string comparison (and the recorded protocol) expects digits.
     */
    public function testEnqueuePassesStringTypedLuaArguments(): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = 1;
        $queue = new RedisStreamJobQueue($fake, 'types', null, 2);
        $queue->enqueue($this->job('job-types-01', 0, 7, 2));

        self::assertSame('2', $fake->evalArgs[2] ?? null, 'capacity travels as string');
        self::assertSame('00000000000000000000', $fake->evalArgs[6] ?? null, 'zero deadline is zero-padded to 20 digits');
        self::assertSame('7', $fake->evalArgs[7] ?? null, 'priority travels as string');
        self::assertSame('2', $fake->evalArgs[8] ?? null, 'attempt travels as string');
    }

    /**
     * Negative deadlines travel as the order-preserving complement
     * encoding — '-' plus the 9's complement of the 19-digit zero-padded
     * magnitude — restoring the signed-domain parity with InMemoryJobQueue
     * and PdoJobQueue (issue #272, docs/JOB-QUEUE-PARITY.md, v2.34.1). The
     * pre-fix unpadded '-5' inverted the Lua's lexicographic ordering and
     * availability verdicts for exactly the same-magnitude case v2.32
     * claimed was safe.
     */
    public function testNegativeDeadlineTravelsOrderPreserving(): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = 1;
        $queue = new RedisStreamJobQueue($fake, 'neg');
        $queue->enqueue($this->job('job-neg-0001', -5));

        self::assertSame('-9999999999999999994', $fake->evalArgs[6] ?? null, 'negative nano deadline is complement-encoded (order-preserving, width 20)');
    }

    /** peek() without a budget passes the documented default of 100 down. */
    public function testDefaultPeekBudgetIsOneHundred(): void
    {
        $fake = new QueueFakeRedis();
        $fake->xRangeResult = [];
        $queue = new RedisStreamJobQueue($fake, 'def');

        self::assertSame([], $queue->peek());
        self::assertSame(['zef:jobq:def', '-', '+', 100], $fake->xRangeCall);
    }

    /**
     * A 9-element flat list (one whole record short of the 18-element
     * field/value contract) is an unexpected dequeue result, not a
     * truncated envelope: the threshold is exactly FIELD_COUNT * 2.
     */
    public function testDequeueRejectsNineElementFieldList(): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = ['job_id', 'job-nine-01', 'job_type', 't.nine', 'payload', 'null',
            'available_at', '00000000000000000000', 'priority'];
        $queue = new RedisStreamJobQueue($fake, 'nine');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis job queue returned an unexpected dequeue result.');
        $queue->dequeue(1);
    }

    /**
     * The storage-boundary id re-assertion must fire for an envelope whose
     * id bypassed the constructor's own validation. Reflection builds the
     * invalid envelope without the constructor (and without unserialize —
     * a semgrep-flagged call form in the tests scan).
     */
    public function testStorageBoundaryRejectsUnserializedInvalidJobId(): void
    {
        $ref = new \ReflectionClass(JobEnvelope::class);
        $broken = $ref->newInstanceWithoutConstructor();
        $id = new \ReflectionProperty(JobEnvelope::class, 'jobId');
        $id->setValue($broken, 'short');
        self::assertSame('short', $broken->jobId, 'the reflected envelope really carries the invalid id');

        $fake = new QueueFakeRedis();
        $fake->evalResult = 1;
        $queue = new RedisStreamJobQueue($fake, 'badid2');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid job ID.');
        $queue->enqueue($broken);
    }

    /**
     * A custom clock drives dequeue()'s default now: the padded fake now
     * travels into the Lua claim script as the third argument (the fake
     * never evaluates Lua, so the OBSERVABLE contract is the argument —
     * a clock-coalesce swap would silently substitute the real clock).
     */
    public function testCustomClockDrivesDefaultNow(): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = 1;
        $queue = new RedisStreamJobQueue($fake, 'clock', static fn (): int => 1_000_000);
        $queue->enqueue($this->job('job-clock-01', 2_000_000));

        $fake->evalResult = false; // next eval (dequeue claim) finds nothing
        self::assertNull($queue->dequeue());
        self::assertSame(
            '00000000000001000000',
            $fake->evalArgs[2] ?? null,
            'the claim script received the fake clock\'s padded now, not the real clock\'s',
        );
    }

    /**
     * The DEFAULT clock closure scales microtime to nanoseconds: the padded
     * now handed to the claim script must be in the 1e9-per-second domain
     * (>= 1.7e18 for any 2023+ wall clock), not a mutant 1e8 domain — the
     * fake records the argument, so the scale assertion is deterministic.
     */
    public function testDefaultClockUsesNanosecondScale(): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = false;
        $queue = new RedisStreamJobQueue($fake, 'scale');

        self::assertNull($queue->dequeue());
        $paddedNow = $fake->evalArgs[2] ?? null;
        self::assertIsString($paddedNow);
        self::assertSame(20, strlen($paddedNow), 'the default clock pads to the 20-digit nano format');
        self::assertGreaterThan(
            1_700_000_000_000_000_000,
            (int) $paddedNow,
            'microtime must scale by 1e9 (nanoseconds), not 1e8 or less',
        );
    }

    #[DataProvider('enqueueResultProvider')]
    public function testEnqueueClassifiesEvalResults(mixed $evalResult, ?string $expectClass, ?string $expectMessage): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = $evalResult;
        $queue = new RedisStreamJobQueue($fake, 'fake');

        if ($expectClass === null) {
            $queue->enqueue($this->job('job-fake-01'));
            self::assertSame(1, $fake->evalCalls, 'eval was invoked exactly once');

            return;
        }

        try {
            $queue->enqueue($this->job('job-fake-01'));
            self::fail('eval result ' . var_export($evalResult, true) . ' must throw');
        } catch (\Throwable $e) {
            self::assertSame($expectClass, $e::class);
            self::assertSame($expectMessage, $e->getMessage());
        }
    }

    /** @return array<string, array{0: mixed, 1: ?class-string<\Throwable>, 2: ?string}> */
    public static function enqueueResultProvider(): array
    {
        return [
            'ok' => [1, null, null],
            'capacity' => [-1, \OverflowException::class, 'Job queue capacity exceeded.'],
            'duplicate' => [-2, RedisJobQueueException::class, "Job 'job-fake-01' is already queued."],
            'unexpected-scalar' => ['bogus', RedisJobQueueException::class, 'Redis job queue returned an unexpected enqueue result.'],
            'unexpected-zero' => [0, RedisJobQueueException::class, 'Redis job queue returned an unexpected enqueue result.'],
        ];
    }

    public function testDequeueMapsTheFlatFieldList(): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = [
            'job_id', 'job-map-0001', 'job_type', 't.map', 'payload', '{"k":"v"}',
            'available_at', '00000000000000001000', 'priority', '4', 'attempt', '2',
            'correlation_id', '', 'trace_parent', '', 'headers', '{"h":"1"}',
        ];
        $queue = new RedisStreamJobQueue($fake, 'map');
        $out = $queue->dequeue(1);

        self::assertInstanceOf(JobEnvelope::class, $out);
        self::assertSame('job-map-0001', $out->jobId);
        self::assertSame('t.map', $out->jobType);
        self::assertSame(['k' => 'v'], $out->payload);
        self::assertSame(1_000, $out->availableAtUnixNano, 'padded string re-hydrates to the exact int');
        self::assertSame(4, $out->priority);
        self::assertSame(2, $out->attempt);
        self::assertNull($out->correlationId, 'empty-string sentinel re-hydrates to null');
        self::assertNull($out->traceParent);
        self::assertSame(['h' => '1'], $out->headers);
    }

    #[DataProvider('dequeueResultProvider')]
    public function testDequeueClassifiesEvalResults(mixed $evalResult, bool $expectNull): void
    {
        $fake = new QueueFakeRedis();
        $fake->evalResult = $evalResult;
        $queue = new RedisStreamJobQueue($fake, 'fake');

        if ($expectNull) {
            self::assertNull($queue->dequeue(1));

            return;
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis job queue returned an unexpected dequeue result.');
        $queue->dequeue(1);
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function dequeueResultProvider(): array
    {
        return [
            'empty-false' => [false, true],
            'empty-nil' => [null, true],
            'short-list' => [['job_id', 'job-x-01'], false],
            'scalar' => ['scalar', false],
        ];
    }

    public function testSizeClassifiesXLenResults(): void
    {
        $fake = new QueueFakeRedis();
        $queue = new RedisStreamJobQueue($fake, 'sz');

        $fake->xLenResult = 7;
        self::assertSame(7, $queue->size());

        $fake->xLenResult = 0;
        self::assertSame(0, $queue->size());

        $fake->xLenResult = false;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis job queue returned an unexpected size result.');
        $queue->size();
    }

    public function testPeekClassifiesXRangeResults(): void
    {
        $fake = new QueueFakeRedis();
        $queue = new RedisStreamJobQueue($fake, 'pk');

        $fake->xRangeResult = [];
        self::assertSame([], $queue->peek(), 'an empty (or missing) stream is an empty array, not an error');

        // phpredis shape: [entry-id => [field => value]] (associative per entry).
        $fake->xRangeResult = [
            '0-1' => ['job_id' => 'job-pk-0001', 'job_type' => 't.pk', 'payload' => 'null',
                'available_at' => '00000000000000000000', 'priority' => '0', 'attempt' => '1',
                'correlation_id' => 'corr-pk-123', 'trace_parent' => '', 'headers' => '{}'],
            '0-2' => ['job_id' => 'job-pk-0002', 'job_type' => 't.pk', 'payload' => 'null',
                'available_at' => '00000000000000000000', 'priority' => '0', 'attempt' => '1',
                'correlation_id' => '', 'trace_parent' => '', 'headers' => '{}'],
        ];
        $jobs = $queue->peek(2);
        self::assertCount(2, $jobs);
        self::assertSame('job-pk-0001', $jobs[0]->jobId);
        self::assertSame('corr-pk-123', $jobs[0]->correlationId);
        self::assertSame('job-pk-0002', $jobs[1]->jobId);

        $fake->xRangeResult = ['not-an-entry'];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis job queue returned an unexpected peek entry.');
        $queue->peek();
    }

    public function testPeekRejectsNonArrayXRange(): void
    {
        $fake = new QueueFakeRedis();
        $fake->xRangeResult = false;
        $queue = new RedisStreamJobQueue($fake, 'pks');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis job queue returned an unexpected peek result.');
        $queue->peek();
    }

    /**
     * Live-server guard: builds a connected client or skips the test —
     * the documented self-skip contract (INSTALLATION.md §8) must survive
     * a dead server, same capture as RedisStoreTest (issue #213).
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

    /**
     * @param array<string, string> $headers
     */
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
}

/*
 * @internal — \Redis fake: eval/xLen/xRange return scheduled results.
 * \Redis is not final so it can be extended; no network connection is
 * made. The conditional declaration keeps the file loadable on profiles
 * without ext-redis (skip-graceful design, same as F8FakeRedis).
 */
if (class_exists(\Redis::class)) {
    final class QueueFakeRedis extends \Redis
    {
        public mixed $evalResult = null;

        public int $evalCalls = 0;

        /** @var null|list<mixed> args of the most recent eval() call */
        public ?array $evalArgs = null;

        public false|int|\Redis $xLenResult = 0;

        /** @var null|list<mixed> the (key, start, end, count) of the most recent xRange() call */
        public ?array $xRangeCall = null;

        public mixed $xRangeResult = null;

        #[\Override] // @phpstan-ignore-line
        public function eval(string $script, array $args = [], int $num_keys = 0): mixed
        {
            ++$this->evalCalls;
            // @var list<mixed> $args
            $this->evalArgs = array_values($args);

            return $this->evalResult;
        }

        #[\Override] // @phpstan-ignore-line
        public function xLen(string $key): false|int|\Redis
        {
            return $this->xLenResult;
        }

        #[\Override] // @phpstan-ignore-line
        public function xRange(string $key, string $start, string $end, int $count = -1): array|bool|\Redis
        {
            $this->xRangeCall = [$key, $start, $end, $count];

            return $this->xRangeResult; // @phpstan-ignore-line
        }
    }
}
