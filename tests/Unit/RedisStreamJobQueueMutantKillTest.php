<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Infrastructure/Job/RedisStreamJobQueue.php (2 escaped).
 *
 * The decodeNano() test is pure (no server). The peek() test needs the live
 * Redis test server (127.0.0.1:6399, password 'zef-test-secret') and skips
 * gracefully when it is unreachable, mirroring RedisStreamJobQueueTest.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobRowCodec;
use Zef\Framework\Job\RedisStreamJobQueue;

/**
 * @internal
 */
final class RedisStreamJobQueueMutantKillTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 6399;
    private const string PASS = 'zef-test-secret';

    private ?\Redis $redis = null;

    protected function tearDown(): void
    {
        if ($this->redis instanceof \Redis && $this->redis->isConnected()) {
            $this->redis->flushDB();
            $this->redis->close();
        }
    }

    /** decodeNano() returns the plain numeric string, no leading zeros (kills UnwrapLtrim:386). */
    public function testDecodeNanoNegativeHasNoLeadingZeros(): void
    {
        self::assertSame('-5', JobRowCodec::decodeNano(JobRowCodec::encodeNano(-5)));
        self::assertSame('-1', JobRowCodec::decodeNano(JobRowCodec::encodeNano(-1)));
        self::assertSame('-100', JobRowCodec::decodeNano(JobRowCodec::encodeNano(-100)));
        self::assertSame('00000000000000000000', JobRowCodec::decodeNano(JobRowCodec::encodeNano(0)));
        self::assertSame('01700000000123456789', JobRowCodec::decodeNano(JobRowCodec::encodeNano(1_700_000_000_123_456_789)));
    }

    /** peek() hydrates the available_at value (kills ArrayItem:293). */
    public function testPeekHydratesAvailableAt(): void
    {
        $queue = new RedisStreamJobQueue($this->liveRedis(), 'peekmk');
        $queue->enqueue(new JobEnvelope('job-peek-01', 't.peek', null, -5, 0, 1, null, null, []));

        $jobs = $queue->peek(10);
        self::assertCount(1, $jobs);
        self::assertSame(-5, $jobs[0]->availableAtUnixNano, 'peek() must hydrate the negative available_at value');
    }

    private function liveRedis(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension not available.');
        }
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
}
