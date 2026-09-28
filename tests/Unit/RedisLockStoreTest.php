<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.24.0 (issue #68) — real Redis-backed distributed lock:
 * SET NX PX acquisition with owner tokens, compare-and-delete release,
 * compare-and-PEXPIRE refresh, TTL expiry, tamper resistance and the
 * input-validation contract shared with InMemoryLockStore.
 *
 * Requires a local Redis-compatible server started with:
 *   --port 6399 --requirepass zef-test-secret
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\RedisLockStore;

/**
 * @internal
 */
final class RedisLockStoreTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 6399;
    private const string PASS = 'zef-test-secret';

    private \Redis $redis;

    private RedisLockStore $store;

    protected function setUp(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension not available.');
        }
        $this->redis = new \Redis();
        // Same phpredis >= 6 RedisException capture as RedisStoreTest (issue #213):
        // the documented self-skip (INSTALLATION.md §8) must survive a dead server.
        $reachable = false;

        try {
            $reachable = $this->redis->pconnect(self::HOST, self::PORT, 2.0) && $this->redis->auth(self::PASS);
        } catch (\RedisException) {
            $reachable = false;
        }
        if (!$reachable) {
            self::markTestSkipped('Redis test server not reachable.');
        }
        $this->redis->select(0);
        $this->redis->flushDB();
        $this->store = new RedisLockStore($this->redis);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis) && $this->redis->isConnected()) {
            $this->redis->flushDB();
        }
    }

    public function testAcquireGrantsLeaseAndReportsHolder(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 30));
        self::assertSame('node-a', $this->store->holder('reports:nightly'));
    }

    public function testAcquireRejectsSecondOwner(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 30));
        self::assertFalse($this->store->acquire('reports:nightly', 'node-b', 30));
        self::assertSame('node-a', $this->store->holder('reports:nightly'));
    }

    public function testReacquireBySameOwnerRefreshesLease(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 2));
        $ttlBefore = $this->ttlOf('reports:nightly'); // milliseconds
        self::assertGreaterThan(0, $ttlBefore);
        self::assertLessThanOrEqual(2_000, $ttlBefore);

        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 30));
        self::assertGreaterThan($ttlBefore, $this->ttlOf('reports:nightly'));
    }

    public function testReleaseOnlySucceedsForTheOwner(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 30));

        self::assertFalse($this->store->release('reports:nightly', 'node-b'));
        self::assertSame('node-a', $this->store->holder('reports:nightly'));

        self::assertTrue($this->store->release('reports:nightly', 'node-a'));
        self::assertNull($this->store->holder('reports:nightly'));
    }

    public function testReleaseOfAFreeLockReturnsFalse(): void
    {
        self::assertFalse($this->store->release('reports:nightly', 'node-a'));
    }

    public function testRefreshExtendsTtlOnlyForTheOwner(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 2));

        self::assertFalse($this->store->refresh('reports:nightly', 'node-b', 30));
        self::assertLessThanOrEqual(2_000, $this->ttlOf('reports:nightly')); // milliseconds

        self::assertTrue($this->store->refresh('reports:nightly', 'node-a', 30));
        self::assertGreaterThan(2_000, $this->ttlOf('reports:nightly'));
    }

    public function testRefreshOfAFreeLockReturnsFalse(): void
    {
        self::assertFalse($this->store->refresh('reports:nightly', 'node-a', 30));
    }

    public function testExpiredLeaseBecomesAcquirableByAnotherNode(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 1));
        // Wait past the PX window: the server itself expires the key, the
        // old holder must observe the loss and the new node must win.
        usleep(1_600_000);
        self::assertNull($this->store->holder('reports:nightly'));
        self::assertFalse($this->store->release('reports:nightly', 'node-a'));
        self::assertTrue($this->store->acquire('reports:nightly', 'node-b', 30));
        self::assertSame('node-b', $this->store->holder('reports:nightly'));
    }

    public function testTamperedValueBlocksReleaseAndRefresh(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 300));
        // Out-of-band overwrite (as if the lease had been stolen): the
        // compare-and-act scripts must refuse to act for the old owner.
        self::assertTrue($this->redis->set($this->redisKey('reports:nightly'), 'hijacked'));

        self::assertFalse($this->store->release('reports:nightly', 'node-a'));
        self::assertFalse($this->store->refresh('reports:nightly', 'node-a', 30));
        self::assertSame('hijacked', $this->store->holder('reports:nightly'));
    }

    public function testKeysAreIsolated(): void
    {
        self::assertTrue($this->store->acquire('reports:nightly', 'node-a', 30));
        self::assertTrue($this->store->acquire('invoices:hourly', 'node-b', 30));
        self::assertSame('node-a', $this->store->holder('reports:nightly'));
        self::assertSame('node-b', $this->store->holder('invoices:hourly'));
        self::assertTrue($this->store->release('reports:nightly', 'node-a'));
        self::assertSame('node-b', $this->store->holder('invoices:hourly'));
    }

    public function testHolderIsNullWhenFree(): void
    {
        self::assertNull($this->store->holder('reports:nightly'));
    }

    /**
     * @dataProvider invalidAcquireProvider
     */
    public function testAcquireValidatesInput(string $key, string $owner, int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->acquire($key, $owner, $ttl);
    }

    /**
     * @return list<array{0:string, 1:string, 2:int}>
     */
    public static function invalidAcquireProvider(): array
    {
        return [
            ['', 'node-a', 30],
            [str_repeat('k', 257), 'node-a', 30],
            ['valid-key', '', 30],
            ['valid-key', str_repeat('o', 257), 30],
            ['valid-key', 'node-a', 0],
            ['valid-key', 'node-a', -1],
            ['valid-key', 'node-a', 86401],
        ];
    }

    public function testReleaseAndRefreshValidateInput(): void
    {
        $rejected = 0;
        foreach ([false, true] as $isRefresh) {
            try {
                $isRefresh
                    ? $this->store->refresh('', 'node-a', 30)
                    : $this->store->release('', 'node-a');
                self::fail('Empty key must be rejected.');
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }

            try {
                $isRefresh
                    ? $this->store->refresh('valid-key', 'node-a', 0)
                    : $this->store->holder(str_repeat('k', 257));
                self::fail('Invalid ttl / oversized key must be rejected.');
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(4, $rejected, 'All validation branches rejected invalid input.');
    }

    private function ttlOf(string $key): int
    {
        $ttl = $this->redis->pttl($this->redisKey($key));

        return (int) $ttl;
    }

    private function redisKey(string $key): string
    {
        return 'zef:lock:' . hash('sha256', $key);
    }
}
