<?php

declare(strict_types=1);

/*
 * Consolidated regressions for the LOW-severity audit batch (#321-#335).
 * HIGH (#300-#305) live in Audit300..Audit305*; fixes already pinned by the
 * updated edge/mutation suites are not duplicated here.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Application;
use Zef\Framework\Cache\CacheInterface;
use Zef\Framework\Cache\TaggableCache;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobQueueInterface;
use Zef\Framework\Job\Scheduler;
use Zef\Framework\Observability\HealthAggregator;
use Zef\Framework\OpenApi\YamlSpecificationSerializer;
use Zef\Framework\Runtime\Async\CancellationTokenSource;
use Zef\Framework\Security\OriginPolicy;
use Zef\Framework\Security\RedisSharedRateLimitStore;
use Zef\Framework\Validation\TrustedHostValidator;

/**
 * @internal
 */
final class AuditLowBatch321to335Test extends TestCase
{
    // ------------------------------------------------------------------
    // #322 — Redis rate-limit bucket identity binds the window size
    // ------------------------------------------------------------------

    public function testRedisBucketKeyBindsWindowSeconds(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis not loaded (the fake extends \Redis).');
        }
        $redis = new class extends \Redis {
            /** @var list<string> */
            public array $bucketKeys = [];
            public mixed $evalResult = ['1', '1234'];

            #[\Override] // @phpstan-ignore-line
            public function eval(string $script, array $args = [], int $numkeys = 0): mixed
            {
                $bucketKey = $args[0] ?? null;
                if (is_string($bucketKey)) {
                    $this->bucketKeys[] = $bucketKey;
                }

                return $this->evalResult;
            }

            #[\Override] // @phpstan-ignore-line
            public function hMGet(string $key, array $fields): array|false|\Redis
            {
                return [];
            }
        };
        $store = new RedisSharedRateLimitStore($redis);

        $store->increment('tenant:1', 60, 1000);
        $store->increment('tenant:1', 120, 1000);
        $store->increment('tenant:1', 60, 1000);

        self::assertNotSame($redis->bucketKeys[0], $redis->bucketKeys[1], 'audit #322: same key, different window must be a DIFFERENT bucket');
        self::assertSame($redis->bucketKeys[0], $redis->bucketKeys[2], 'audit #322: same key + same window must reuse the bucket');
    }

    // ------------------------------------------------------------------
    // #323 — a throwing cancellation callback must not drop the rest
    // ------------------------------------------------------------------

    public function testThrowingCancellationCallbackDoesNotDropRemainingCallbacks(): void
    {
        $source = new CancellationTokenSource();
        $token = $source->token();
        $ran = [];
        $token->register(static function () use (&$ran): void {
            $ran[] = 'a';
        });
        $token->register(static function (): never {
            throw new \RuntimeException('boom');
        });
        $token->register(static function () use (&$ran): void {
            $ran[] = 'c';
        });

        try {
            $source->cancel();
            self::fail('the first callback error must still surface to the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['a', 'c'], $ran, 'audit #323: every registered callback ran despite the middle one throwing');
        self::assertFalse($source->cancel(), 'cancel() stays idempotent after the error path');
        self::assertSame(['a', 'c'], $ran, 'no callback fires twice');
    }

    // ------------------------------------------------------------------
    // #324 — re-registering a job keeps its computed cursor
    // ------------------------------------------------------------------

    public function testSchedulerReregistrationKeepsTheCursor(): void
    {
        $scheduler = new Scheduler(new AuditLowFakeQueue());
        $scheduler->register('audit.low.job', ['k' => 1], CronExpression::parse('* * * * *'));
        $now = 1_700_000_000 * 1_000_000_000;
        $scheduler->tick($now);

        $cursor = $scheduler->nextRunOf('audit.low.job');
        self::assertNotNull($cursor, 'tick() computes the next-run cursor');

        // Config reload / schedule swap: same jobType, new payload+schedule.
        $scheduler->register('audit.low.job', ['k' => 2], CronExpression::parse('*/5 * * * *'));

        self::assertSame(
            $cursor,
            $scheduler->nextRunOf('audit.low.job'),
            'audit #324: re-registration honours the documented "keeping its cursor"',
        );
    }

    // ------------------------------------------------------------------
    // #325 — Feb-29-only expressions across a non-leap century year
    // ------------------------------------------------------------------

    public function testCronFeb29AcrossNonLeapCenturyFiresInsteadOfThrowing(): void
    {
        $expr = CronExpression::parse('0 0 29 2 *');
        $from = new \DateTimeImmutable('2097-03-01T00:00:00+00:00')->getTimestamp() * 1_000_000_000;
        $expected = new \DateTimeImmutable('2104-02-29T00:00:00+00:00')->getTimestamp() * 1_000_000_000;

        // The old 4-year budget exhausted before 2104-02-29 and threw a
        // spurious "no matching minute within 4 years".
        self::assertSame($expected, $expr->nextRunAfter($from));
    }

    // ------------------------------------------------------------------
    // #326 — the reverse key->tags index carries a bounded TTL
    // ------------------------------------------------------------------

    public function testReverseKeyTagsIndexIsBoundedByTheValueTtl(): void
    {
        $recorder = new AuditLowRecordingCache();
        $cache = new TaggableCache($recorder);

        $cache->setWithTags('prod:1', 'chair', 300, ['products']);
        $reverse = $recorder->setCalls["\0zef-keytags:prod:1"] ?? null;
        self::assertNotNull($reverse, 'the reverse index entry is written');
        self::assertSame(300, $reverse['ttl'], 'audit #326: the reverse index never outlives a TTL-bearing value');

        $recorder2 = new AuditLowRecordingCache();
        $cache2 = new TaggableCache($recorder2);
        $cache2->setWithTags('prod:2', 'desk', null, ['products']);
        $reverse2 = $recorder2->setCalls["\0zef-keytags:prod:2"] ?? null;
        self::assertNotNull($reverse2);
        self::assertSame(86400, $reverse2['ttl'], 'audit #326: a no-expiry value still gets the 24h sliding lease');
    }

    // ------------------------------------------------------------------
    // #329 — whitespace-padded Content-Length must still trip the early 413
    // ------------------------------------------------------------------

    public function testWhitespacePaddedContentLengthTripsTheEarly413(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/x',
            'HTTP_HOST' => 'localhost',
            'CONTENT_LENGTH' => ' 999999',
        ];

        $this->expectException(PayloadTooLargeException::class);
        RequestFactory::fromServer($server, bodyPolicy: new RequestBodyPolicy(8));
    }

    public function testExactContentLengthStillPasses(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/x',
            'HTTP_HOST' => 'localhost',
            'CONTENT_LENGTH' => '5',
        ];
        $request = RequestFactory::fromServer($server, bodyPolicy: new RequestBodyPolicy(8));

        self::assertSame('POST', $request->getMethod());
    }

    // ------------------------------------------------------------------
    // #330 — user-info-only origins are malformed, not silently stripped
    // ------------------------------------------------------------------

    public function testUserInfoOnlyOriginIsRejectedAsMalformed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed Origin header.');

        OriginPolicy::normalizeOrigin('https://user@example.com');
    }

    public function testCleanOriginStillNormalizes(): void
    {
        self::assertSame('https://example.com', OriginPolicy::normalizeOrigin('https://example.com'));
    }

    // ------------------------------------------------------------------
    // #331 — empty allow-list leniency is explicit / misconfig is loud
    // ------------------------------------------------------------------

    public function testTrustedHostValidatorFailClosedOptIn(): void
    {
        // Default stays lenient (BC: Uri relative references, opt-in pinning).
        new TrustedHostValidator()->assertTrusted('anything.example');

        // Opt-in fail-closed posture: an empty list is a misconfiguration.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pinning is misconfigured');

        new TrustedHostValidator([], true)->assertTrusted('anything.example');
    }

    public function testSetTrustedHostsRefusesAnAllEmptyEntriesList(): void
    {
        $app = new Application(bodyPolicy: new RequestBodyPolicy(4));
        $app->setTrustedHosts([]); // literal [] stays the documented no-pinning default

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refusing to silently disable');

        // e.g. ZEF_TRUSTED_HOSTS=",," exploded and trimmed: pinning was
        // INTENDED, but every entry filtered away — the old code silently
        // disabled host-header validation.
        $app->setTrustedHosts(['', '']);
    }

    // ------------------------------------------------------------------
    // #332 — zero indicators is degraded, never a green /health
    // ------------------------------------------------------------------

    public function testHealthAggregatorWithZeroIndicatorsIsDegraded(): void
    {
        $aggregator = new HealthAggregator();
        $report = $aggregator->aggregate();

        self::assertSame('degraded', $report['status'], 'audit #332: no probes must never report ok');
        self::assertCount(1, $report['checks']);
        self::assertSame('health.indicators', $report['checks'][0]['name']);
        self::assertSame('down', $report['checks'][0]['status']);
        self::assertStringContainsString('no health indicators registered', $report['checks'][0]['message']);
        self::assertStringContainsString('zef_health_status 0', $aggregator->toPrometheus());
    }

    // ------------------------------------------------------------------
    // #333 — a leading "? " is quoted (YAML explicit-key indicator)
    // ------------------------------------------------------------------

    public function testYamlQuotesLeadingQuestionMarkSpace(): void
    {
        $yaml = new YamlSpecificationSerializer()->serialize([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'demo',
                'description' => '? explicit key hazard',
            ],
        ]);

        self::assertStringContainsString('"? explicit key hazard"', $yaml, 'audit #333: "? ..." must be emitted quoted');
    }
}

/**
 * @internal
 */
final class AuditLowFakeQueue implements JobQueueInterface
{
    /** @var list<JobEnvelope> */
    public array $enqueued = [];

    #[\Override]
    public function enqueue(JobEnvelope $job): void
    {
        $this->enqueued[] = $job;
    }

    #[\Override]
    public function dequeue(?int $nowUnixNano = null): ?JobEnvelope
    {
        return null;
    }

    #[\Override]
    public function size(): int
    {
        return count($this->enqueued);
    }
}

/**
 * @internal
 */
final class AuditLowRecordingCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, ttl: ?int}> */
    public array $setCalls = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->setCalls[$key] = ['value' => $value, 'ttl' => $ttlSeconds];
    }

    #[\Override]
    public function delete(string $key): void {}

    #[\Override]
    public function has(string $key): bool
    {
        return isset($this->setCalls[$key]);
    }

    #[\Override]
    public function clear(): void
    {
        $this->setCalls = [];
    }
}
