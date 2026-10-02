<?php

declare(strict_types=1);

/*
 * Regressions for the final two LOW-severity findings of the v2.34.x deep
 * logic audit: #319 (rate-limit algorithm 'fixed' accepted but silently
 * running the sliding-window limiter) and #320 (explicit CSRF TTL 0
 * clamped to a 1-second TTL). The HIGH/MEDIUM/LOW batches that shipped
 * earlier live in Audit300..Audit305*, AuditMedium306to318RegressionTest
 * and AuditLowBatch321to335Test; with these two the audit backlog #300-#335
 * is fully closed.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Framework\Security\TokenBucketRateLimiter;
use Zef\Middleware\SecurityRateLimitWiring;

/**
 * @internal
 */
final class AuditLow319to320RegressionTest extends TestCase
{
    // ------------------------------------------------------------------
    // #319 — every accepted algorithm name wires its OWN implementation
    // ------------------------------------------------------------------

    public function testFixedAlgorithmWiresTheGenuineFixedWindowLimiter(): void
    {
        $middleware = $this->wired(['ZEF_SECURITY_RATE_LIMIT_ALGORITHM' => 'fixed']);

        self::assertInstanceOf(
            InMemoryRateLimiter::class,
            $this->innerLimiter($middleware),
            'fixed must run the counter-per-window implementation, not the sliding-window limiter',
        );
    }

    public function testSlidingAndTokenStillWireTheirOwnLimiters(): void
    {
        $sliding = $this->wired(['ZEF_SECURITY_RATE_LIMIT_ALGORITHM' => 'sliding']);
        self::assertInstanceOf(SlidingWindowRateLimiter::class, $this->innerLimiter($sliding));

        $token = $this->wired(['ZEF_SECURITY_RATE_LIMIT_ALGORITHM' => 'token']);
        self::assertInstanceOf(TokenBucketRateLimiter::class, $this->innerLimiter($token));
    }

    /**
     * The pre-fix wiring would have answered 200 here too (sliding is
     * behaviourally similar for a fresh bucket), so the quota check alone
     * cannot catch #319 — the instance assertion above is the real guard.
     * This test pins the end-to-end promise: an operator selecting 'fixed'
     * gets a working fixed-window quota, not just a non-crashing boot.
     */
    public function testFixedAlgorithmEnforcesTheConfiguredQuota(): void
    {
        $middleware = $this->wired([
            'ZEF_SECURITY_RATE_LIMIT_ALGORITHM' => 'fixed',
            'ZEF_SECURITY_RATE_LIMIT_TIERS' => (string) json_encode([
                ['name' => 'api', 'limit' => 2, 'windowSeconds' => 60, 'pathPrefix' => '/api'],
            ]),
        ]);

        $first = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('1', $first->getHeaderLine('RateLimit-Remaining'));

        $second = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('0', $second->getHeaderLine('RateLimit-Remaining'));

        $third = $middleware->process($this->request('/api'), $this->handler());
        self::assertSame(429, $third->getStatusCode(), 'third hit within the same window is over the 2-quota');
        self::assertSame('60', $third->getHeaderLine('Retry-After'), 'fixed window: the whole 60s window is ahead');
    }

    // ------------------------------------------------------------------
    // #320 — explicit ZEF_SECURITY_CSRF_TTL=0 means "no expiry", not 1s
    // ------------------------------------------------------------------

    public function testExplicitZeroCsrfTtlMeansNoExpiry(): void
    {
        $policy = SecurityPolicy::fromEnvironment(
            null,
            new AuditLow319EnvStub(['ZEF_SECURITY_CSRF_TTL' => '0']),
        );

        self::assertSame(
            0,
            $policy->csrfTokenTtlSeconds,
            'explicit 0 is the documented "no expiry" value and must survive parsing (was clamped to 1)',
        );
    }

    public function testCsrfTtlParsingKeepsPositiveValuesAndDefaults(): void
    {
        $unset = SecurityPolicy::fromEnvironment(null, new AuditLow319EnvStub([]));
        self::assertSame(0, $unset->csrfTokenTtlSeconds, 'unset falls back to the 0 default (no expiry)');

        $twoHours = SecurityPolicy::fromEnvironment(
            null,
            new AuditLow319EnvStub(['ZEF_SECURITY_CSRF_TTL' => '7200']),
        );
        self::assertSame(7200, $twoHours->csrfTokenTtlSeconds);

        $typo = SecurityPolicy::fromEnvironment(
            null,
            new AuditLow319EnvStub(['ZEF_SECURITY_CSRF_TTL' => '72OO']),
        );
        self::assertSame(0, $typo->csrfTokenTtlSeconds, 'non-digit typo falls back to the default, never a silent (int) cast');

        $negative = SecurityPolicy::fromEnvironment(
            null,
            new AuditLow319EnvStub(['ZEF_SECURITY_CSRF_TTL' => '-5']),
        );
        self::assertSame(0, $negative->csrfTokenTtlSeconds, 'ctype_digit rejects signed forms; default applies');
    }

    /**
     * End-to-end counterpart of the parsing matrix: a 0-TTL token manager
     * must never reject a token because of age (no TTL window at all).
     */
    public function testZeroTtlTokenManagerSkipsExpiryWindowEntirely(): void
    {
        $manager = new CsrfTokenManager(
            secret: str_repeat('k', 32),
            ttlSeconds: 0,
        );

        // Same binding context on both sides (the MAC covers it, issue #317):
        // a zero-TTL token is a two-part legacy wire format with no
        // timestamp, so age can never be a rejection reason.
        $token = $manager->issue('session-42');
        self::assertTrue($manager->isValid($token, 'session-42'), '0 = no expiry: the token stays valid regardless of age');
        self::assertCount(
            2,
            explode('.', $token),
            'legacy no-TTL wire format is <token>.<mac> (two parts, no issuedAt)',
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, string> $values */
    private function wired(array $values): RateLimitMiddleware
    {
        return SecurityRateLimitWiring::rateLimitMiddleware(
            new AuditLow319EnvStub($values),
            null,
        );
    }

    /** Digs middleware -> tiered -> limiter for the concrete implementation. */
    private function innerLimiter(RateLimitMiddleware $middleware): RateLimiterInterface
    {
        $tieredProp = new \ReflectionProperty($middleware, 'tiered');
        $tiered = $tieredProp->getValue($middleware);
        if (!$tiered instanceof TieredRateLimiter) {
            throw new \LogicException('RateLimitMiddleware::tiered must hold a TieredRateLimiter.');
        }
        $limiterProp = new \ReflectionProperty($tiered, 'limiter');
        $limiter = $limiterProp->getValue($tiered);
        if (!$limiter instanceof RateLimiterInterface) {
            throw new \LogicException('TieredRateLimiter::limiter must hold a RateLimiterInterface.');
        }

        return $limiter;
    }

    private function request(string $path = '/'): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']), [], [], [], [], null, []);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }
}

/** Minimal in-memory EnvInterface port (same pattern as AuditMediumEnvStub). */
final class AuditLow319EnvStub implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = []) {}

    #[\Override]
    public function readInt(
        string $name,
        int $default,
        int $min,
        int $max,
        bool $strict = false,
    ): int {
        $raw = $this->values[$name] ?? null;

        return $raw === null ? $default : max($min, min($max, (int) $raw));
    }

    #[\Override]
    public function readBool(string $name, bool $default = false): bool
    {
        $raw = $this->values[$name] ?? null;

        return $raw === null ? $default : filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    #[\Override]
    public function readBoolStrict(string $name, bool $default = false): bool
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $value = strtolower(trim($raw));
        if (in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }

        throw new \InvalidArgumentException(sprintf(
            "%s='%s' is not a recognized boolean (allowed: 1/0, true/false, yes/no, on/off, enabled/disabled).",
            $name,
            $raw,
        ));
    }

    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    #[\Override]
    public function readCsv(string $name): array
    {
        $raw = $this->values[$name] ?? '';

        return $raw === '' ? [] : array_map(trim(...), explode(',', $raw));
    }
}
