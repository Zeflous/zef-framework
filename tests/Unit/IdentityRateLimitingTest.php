<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\AuthenticationMiddleware;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\CredentialProviderInterface;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\ReplayResult;
use Zef\Framework\Security\Distributed\SecurityAdmissionDecision;
use Zef\Framework\Security\Distributed\SecurityBoundaryInterface;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;

/**
 * v2.31.0 — Identity-aware rate limiting: AuthenticationMiddleware now sets
 * the canonical `zef.auth.identity` attribute for admitted non-anonymous
 * principals (activating the per-identity tier that was previously dead
 * code — audit I-2), and the client-controlled X-API-Key header is no longer
 * trusted by default (audit C-2).
 *
 * @internal
 */
final class IdentityRateLimitingTest extends TestCase
{
    // -------------------------------------------------- attribute wiring

    public function testAdmittedPrincipalsGetTheCanonicalIdentityAttribute(): void
    {
        $stack = $this->authenticatedStack('user-7');
        $captured = $this->capturingHandler();

        $stack->process($this->bearerRequest(), $captured);

        self::assertSame('user-7', $captured->attributes['zef.security.principal'] ?? null);
        self::assertSame('user-7', $captured->attributes['zef.auth.identity'] ?? null);
    }

    public function testAnonymousTrafficGetsNoIdentityAttribute(): void
    {
        $stack = $this->authenticatedStack(null);
        $captured = $this->capturingHandler();

        $stack->process($this->plainGetRequest(), $captured);

        self::assertSame('anonymous', $captured->attributes['zef.security.principal'] ?? null);
        self::assertArrayNotHasKey('zef.auth.identity', $captured->attributes);
    }

    // -------------------------------------------------- per-identity tier end-to-end

    public function testAuthenticatedUsersGetIndependentQuotaBuckets(): void
    {
        $rateLimiter = $this->rateLimiterStack([new RateLimitRule('api', 1, 10)]);

        self::assertSame(200, $rateLimiter->process($this->asUser('user-7'), $this->ok())->getStatusCode());
        self::assertSame(
            429,
            $rateLimiter->process($this->asUser('user-7'), $this->ok())->getStatusCode(),
            'the same principal consumes its own per-user bucket (I-2 tier activated)',
        );
        self::assertSame(
            200,
            $rateLimiter->process($this->asUser('user-8'), $this->ok())->getStatusCode(),
            'a different principal has an independent bucket',
        );
    }

    public function testRotatedUnverifiedHeadersCanNoLongerBypassPerIpQuota(): void
    {
        $rateLimiter = $this->rateLimiterStack([new RateLimitRule('api', 1, 10)]);

        self::assertSame(200, $rateLimiter->process($this->withApiKey('key-1'), $this->ok())->getStatusCode());
        for ($i = 2; $i <= 10; ++$i) {
            self::assertSame(
                429,
                $rateLimiter->process($this->withApiKey('key-' . $i), $this->ok())->getStatusCode(),
                "rotation #$i must stay in the per-IP bucket (audit C-2 bypass closed)",
            );
        }
    }

    public function testOptedInHeaderStillBucketsPerKey(): void
    {
        $rateLimiter = $this->rateLimiterStack([new RateLimitRule('api', 1, 10)], trustHeader: true);

        self::assertSame(200, $rateLimiter->process($this->withApiKey('k1'), $this->ok())->getStatusCode());
        self::assertSame(429, $rateLimiter->process($this->withApiKey('k1'), $this->ok())->getStatusCode());
        self::assertSame(200, $rateLimiter->process($this->withApiKey('k2'), $this->ok())->getStatusCode());
    }

    public function testAttributedIdentityBeatsSpoofedHeaders(): void
    {
        $rateLimiter = $this->rateLimiterStack([new RateLimitRule('api', 1, 10)]);
        $attributed = $this->header($this->asUser('user-7'), 'spoofed-rotation-1');
        $spoofed = $this->withApiKey('spoofed-rotation-2');

        self::assertSame(200, $rateLimiter->process($attributed, $this->ok())->getStatusCode());
        self::assertSame(429, $rateLimiter->process($this->header($attributed, 'spoofed-rotation-3'), $this->ok())->getStatusCode());
        self::assertSame(
            200,
            $rateLimiter->process($spoofed, $this->ok())->getStatusCode(),
            'the header-only request lives in the IP bucket — never the attributed one',
        );
    }

    // -------------------------------------------------- harness

    /**
     * AuthenticationMiddleware wired to admit `$principal` (null = anonymous).
     */
    private function authenticatedStack(?string $principal): AuthenticationMiddleware
    {
        $context = $principal === null
            ? null
            : new SecurityContext(
                principalId: $principal,
                authenticationMethod: 'bearer',
                authorizationContext: 'default',
                credentialScope: 'api',
                peerIdentity: null,
            );

        return new AuthenticationMiddleware(
            new AuthProviderDouble($context),
            new AllowPolicyDouble(),
            new AllowReplayProtectorDouble(),
            new AllowBoundaryDouble(),
        );
    }

    /**
     * @param list<RateLimitRule> $rules
     */
    private function rateLimiterStack(array $rules, bool $trustHeader = false): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            new TieredRateLimiter(new SlidingWindowRateLimiter($this->fixedClock())),
            $rules,
            [],
            false,
            'X-API-Key',
            $trustHeader,
        );
    }

    private function fixedClock(): CacheClockInterface
    {
        return new class implements CacheClockInterface {
            #[\Override]
            public function nowUnixNano(): int
            {
                return 1_700_000_000_000_000_000;
            }
        };
    }

    private function bearerRequest(): ServerRequestInterface
    {
        return new ServerRequest(
            'GET',
            new Uri('http://localhost/api', ['localhost']),
            [],
            [],
            [],
            [],
            null,
            ['Authorization' => 'Bearer token-7'],
        );
    }

    private function plainGetRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost/api', ['localhost']));
    }

    private function asUser(string $principal): ServerRequestInterface
    {
        return $this->bearerRequest()->withAttribute('zef.auth.identity', $principal);
    }

    private function withApiKey(string $key): ServerRequestInterface
    {
        return $this->header($this->plainGetRequest(), $key);
    }

    private function header(ServerRequestInterface $request, string $key): ServerRequestInterface
    {
        $withHeader = $request->withHeader('X-API-Key', $key);
        if (!$withHeader instanceof ServerRequestInterface) {
            throw new \LogicException('withHeader must preserve the request type.');
        }

        return $withHeader;
    }

    private function ok(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }

    private function capturingHandler(): CapturingHandler
    {
        return new CapturingHandler();
    }
}

/**
 * @internal
 */
final class CapturingHandler implements RequestHandlerInterface
{
    /** @var array<string, mixed> */
    public array $attributes = [];

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        foreach ($request->getAttributes() as $name => $value) {
            if (is_string($name)) {
                $this->attributes[$name] = $value;
            }
        }

        return new Response(200, [], 'ok');
    }
}

/**
 * @internal
 */
final class AuthProviderDouble implements CredentialProviderInterface
{
    public function __construct(
        private readonly ?SecurityContext $context,
    ) {}

    #[\Override]
    public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
    {
        if ($this->context instanceof SecurityContext && $handle->scope === 'bearer') {
            return new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $this->context);
        }

        return new AuthenticationResult(AuthenticationStatus::UNAUTHENTICATED);
    }
}

/**
 * @internal
 */
final class AllowPolicyDouble implements AuthorizationPolicyInterface
{
    #[\Override]
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
    {
        return new AuthorizationResult(SecurityVerdict::ALLOW, 'default');
    }
}

/**
 * @internal
 */
final class AllowReplayProtectorDouble implements ReplayProtectorInterface
{
    #[\Override]
    public function check(?string $replayId, int $nowMs): ReplayResult
    {
        return new ReplayResult(ReplayDecision::ACCEPT);
    }
}

/**
 * @internal
 */
final class AllowBoundaryDouble implements SecurityBoundaryInterface
{
    #[\Override]
    public function admit(
        AuthenticationResult $authentication,
        SecurityRequest $request,
        AuthorizationPolicyInterface $authorization,
        ReplayProtectorInterface $replayProtector,
        int $nowMs,
    ): SecurityAdmissionDecision {
        // Always admit: this double exists to exercise the ATTRIBUTE WIRING
        // and the rate-limit identity chain, not the admission policy.
        return new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false);
    }
}
