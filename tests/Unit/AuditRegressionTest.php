<?php

declare(strict_types=1);

/*
 * ZEF Framework — regression tests for the runtime audit findings
 * (issues #278–#282). Each test pins the fixed behaviour so the
 * corresponding bug cannot silently return.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\RedisJobQueueException;
use Zef\Framework\Job\RedisStreamJobQueue;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\UrlGenerator;
use Zef\Framework\Validation\TrustedHostValidator;

/**
 * @internal
 */
final class AuditRegressionTest extends TestCase
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

    // ------------------------------------------------------------------
    // BUG-01 / BUG-04 — TrustedHostValidator malformed allow-list entries
    // ------------------------------------------------------------------

    /**
     * An array entry must be rejected with a clean InvalidArgumentException
     * and NO "Array to string conversion" warning (fatal under
     * failOnWarning=true). Issue #278.
     */
    public function testTrustedHostValidatorRejectsArrayEntryWithoutWarning(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trusted host entries must be strings, got array.');

        new TrustedHostValidator([['nested']]);
    }

    /**
     * An object entry must raise a catchable \Exception, not an uncaught
     * \Error from the string cast. Issue #281.
     */
    public function testTrustedHostValidatorRejectsObjectEntryWithException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Trusted host entries must be strings, got stdClass.');

        new TrustedHostValidator([new \stdClass()]);
    }

    public function testTrustedHostValidatorStillAcceptsStringEntries(): void
    {
        $validator = new TrustedHostValidator(['  Example.COM ', '::1']);

        $validator->assertTrusted('example.com');
        $validator->assertTrusted('[::1]');
        self::expectNotToPerformAssertions();
    }

    // ------------------------------------------------------------------
    // BUG-02 — UrlGenerator null contract
    // ------------------------------------------------------------------

    public function testUrlGeneratorRejectsNullParameter(): void
    {
        $gen = $this->generator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'generic' parameter 'v' must be scalar or Stringable, got null.");

        // Deliberately violates the documented array<string,scalar|\Stringable>
        // contract to prove the runtime rejects null.
        // @phpstan-ignore argument.type
        $gen->generate('generic', ['v' => null]);
    }

    // ------------------------------------------------------------------
    // BUG-05 — UrlGenerator empty-segment / self-match invariant
    // ------------------------------------------------------------------

    public function testUrlGeneratorRejectsEmptyStringifyingParameter(): void
    {
        $gen = $this->generator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'generic' parameter 'v' must not be empty.");

        $gen->generate('generic', ['v' => false]);
    }

    /**
     * The documented invariant: a generated URL must always match its own
     * route. Issue #282.
     */
    public function testUrlGeneratorGeneratedUrlMatchesItsOwnRoute(): void
    {
        $router = new Router();
        $router->add('GET', '/u/{id}', 'h', name: 'u');
        $router->freeze();
        $gen = new UrlGenerator($router);

        $url = $gen->generate('u', ['id' => 42]);
        self::assertSame('/u/42', $url);

        $matched = $router->match('GET', $url);
        self::assertSame('/u/{id}', $matched['pattern']);
        self::assertSame('42', $matched['params']['id']);
    }

    // ------------------------------------------------------------------
    // BUG-03 — RedisStreamJobQueue corrupt short-positive available_at
    // ------------------------------------------------------------------

    /**
     * A corrupt entry whose available_at is a short positive string (e.g.
     * '20') sorts above every 20-char 'now' string. Before the fix it was
     * never claimed, never deleted and never reported — the stream wedged
     * forever. After the fix it stays claimable, surfaces exactly once and
     * the queue keeps flowing. Issue #280.
     */
    public function testCorruptShortPositiveAvailableAtDoesNotWedgeQueue(): void
    {
        $redis = $this->liveRedis();
        $queue = new RedisStreamJobQueue($redis, 'wedge');

        $queue->enqueue(new JobEnvelope('job-good-01', 't.good', null, 0, 0, 1, null, null, []));

        // Tamper: a corrupt entry with a short positive available_at.
        $redis->eval(
            <<<'LUA'
                redis.call('SADD', KEYS[2], 'job-bad-0001')
                redis.call('XADD', KEYS[1], '*',
                    'job_id', 'job-bad-0001', 'job_type', 't.bad', 'payload', 'null',
                    'available_at', '20', 'priority', '0', 'attempt', '1',
                    'correlation_id', '', 'trace_parent', '', 'headers', '{}')
                LUA,
            ['zef:jobq:wedge', 'zef:jobq:wedge:ids'],
            2,
        );

        // The good entry (available_at 0) is claimed first.
        $good = $queue->dequeue(1);
        self::assertInstanceOf(JobEnvelope::class, $good);
        self::assertSame('job-good-01', $good->jobId);

        // The corrupt entry is now claimable and surfaces exactly once.
        try {
            $queue->dequeue(1);
            self::fail('the corrupt entry must surface loudly');
        } catch (RedisJobQueueException $e) {
            self::assertStringContainsString('corrupt available_at', $e->getMessage());
        }

        // The stream is drained — no permanent wedge.
        self::assertSame(0, $queue->size(), 'the corrupt entry was destroyed by its claim');
        self::assertNull($queue->dequeue(1), 'the queue keeps flowing');
    }

    private function generator(): UrlGenerator
    {
        $router = new Router();
        $router->add('GET', '/static', 'h', name: 'static');
        $router->add('GET', '/f/{v:hex}', 'h', name: 'files');
        $router->add('GET', '/g/{v}', 'h', name: 'generic');

        return new UrlGenerator($router);
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
