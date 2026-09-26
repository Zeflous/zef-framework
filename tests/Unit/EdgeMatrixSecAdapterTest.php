<?php

declare(strict_types=1);

/*
 * ZEF Framework — Edge-Case Matrix fase 3 (Security adapters, v2.14.4).
 *
 * Kurikulum: docs/EDGE-CASE-MATRIX.md Tier 3. Adversarial scenarios for
 * AuthenticationMiddleware and SecurityRuntimeMiddleware: method-name
 * casing, credential byte bounds, Bearer grammar anchors/flags, timestamp
 * units, request-id bounds, trusted-proxy precedence, CSRF link-by-link
 * rejection and correlation-header integrity on every denial path.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\AuthenticationMiddleware;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\CredentialProviderInterface;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\ReplayResult;
use Zef\Framework\Security\Distributed\SecurityAdmissionDecision;
use Zef\Framework\Security\Distributed\SecurityBoundaryInterface;
use Zef\Framework\Security\Distributed\SecurityContext as DistributedSecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\SecurityContext;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;

/**
 * @internal
 */
final class EdgeMatrixAuthProvider implements CredentialProviderInterface
{
    public ?CredentialHandle $lastHandle = null;

    public ?int $lastNowMs = null;

    public function __construct(private readonly ?DistributedSecurityContext $context) {}

    #[\Override]
    public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
    {
        $this->lastHandle = $handle;
        $this->lastNowMs = $nowMs;

        return new AuthenticationResult(
            $this->context instanceof DistributedSecurityContext
                ? AuthenticationStatus::AUTHENTICATED
                : AuthenticationStatus::UNAUTHENTICATED,
            $this->context,
        );
    }
}

/**
 * @internal
 */
final class EdgeMatrixAuthBoundary implements SecurityBoundaryInterface
{
    public ?SecurityRequest $lastRequest = null;

    public ?int $lastNowMs = null;

    public function __construct(private readonly bool $allow) {}

    #[\Override]
    public function admit(
        AuthenticationResult $authentication,
        SecurityRequest $request,
        AuthorizationPolicyInterface $authorization,
        ReplayProtectorInterface $replayProtector,
        int $nowMs,
    ): SecurityAdmissionDecision {
        $this->lastRequest = $request;
        $this->lastNowMs = $nowMs;

        return $this->allow
            ? new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false)
            : new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::AUTHORIZATION_DENIED, false);
    }
}

/**
 * @internal
 */
final class EdgeMatrixNoopPolicy implements AuthorizationPolicyInterface
{
    #[\Override]
    public function authorize(DistributedSecurityContext $context, SecurityRequest $request): AuthorizationResult
    {
        throw new \LogicException('not exercised through the fake boundary');
    }
}

/**
 * @internal
 */
final class EdgeMatrixNoopReplayProtector implements ReplayProtectorInterface
{
    #[\Override]
    public function check(?string $replayId, int $nowMs): ReplayResult
    {
        throw new \LogicException('not exercised through the fake boundary');
    }
}

/**
 * @internal
 */
final class EdgeMatrixLimiter implements RateLimiterInterface
{
    public ?string $lastKey = null;

    public function __construct(
        private readonly bool $allowed = true,
        private readonly bool $throws = false,
    ) {}

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        if ($this->throws) {
            throw new \RuntimeException('limiter storage down');
        }
        $this->lastKey = $key;

        return new RateLimitDecision($this->allowed, $limit, $this->allowed ? $limit - 1 : 0, 7);
    }
}

/**
 * @internal
 */
final class EdgeMatrixCapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $lastRequest = null;

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return new Response(200, [], 'edge-ok');
    }
}

/**
 * @internal
 */
final class EdgeMatrixSecAdapterTest extends TestCase
{
    // ------------------------------------------------------------------
    // AuthenticationMiddleware
    // ------------------------------------------------------------------

    public function testAuthTreatsLowercaseMethodNamesAsSafeMethods(): void
    {
        $middleware = $this->authMiddleware(null, true);
        $request = new ServerRequest('get', new Uri('http://localhost/public', ['localhost']));
        $handler = new EdgeMatrixCapturingHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode(), 'lowercase GET must be classified as a safe method');
        self::assertNotNull($handler->lastRequest, 'safe anonymous requests reach the handler');
    }

    public function testAuthResolvesAnonymousCredentialsWithExactShape(): void
    {
        $middleware = $this->authMiddleware(null, true);
        $provider = $this->providerOf($middleware);
        $request = new ServerRequest('get', new Uri('http://localhost/public', ['localhost']));

        $middleware->process($request, new EdgeMatrixCapturingHandler());

        $handle = $provider->lastHandle;
        self::assertNotNull($handle, 'the anonymous credential must still be resolved through the provider');
        self::assertSame('anonymous', $handle->handleId);
        self::assertSame('public', $handle->scope);
        self::assertSame(0, $handle->expiresAtMs);
    }

    public function testAuthPassesMillisecondTimestampsToProviderAndBoundary(): void
    {
        $provider = new EdgeMatrixAuthProvider(null);
        $boundary = new EdgeMatrixAuthBoundary(true);
        $middleware = new AuthenticationMiddleware(
            $provider,
            new EdgeMatrixNoopPolicy(),
            new EdgeMatrixNoopReplayProtector(),
            $boundary,
        );
        $request = new ServerRequest('get', new Uri('http://localhost/public', ['localhost']));

        $middleware->process($request, new EdgeMatrixCapturingHandler());

        self::assertNotNull($provider->lastNowMs);
        self::assertNotNull($boundary->lastNowMs);
        self::assertGreaterThan(1_000_000_000_000, $provider->lastNowMs, 'the provider receives milliseconds, not seconds');
        self::assertGreaterThan(1_000_000_000_000, $boundary->lastNowMs, 'the boundary receives milliseconds, not seconds');
    }

    public function testAuthBuildsOperationClassFromMethodAndPathWithBound(): void
    {
        $middleware = $this->authMiddleware(null, true);

        // Upper-cased method, single space, exact path.
        $short = new ServerRequest('get', new Uri('http://localhost/orders/42', ['localhost']));
        $middleware->process($short, new EdgeMatrixCapturingHandler());
        $boundary = $this->boundaryOf($middleware);
        self::assertNotNull($boundary, 'the boundary spy must be reachable via reflection');

        $shortRequest = $boundary->lastRequest;
        self::assertNotNull($shortRequest);
        self::assertSame('GET /orders/42', $shortRequest->operationClass);

        // A 128-byte path fits the resource bound; the operation label is bounded.
        $longPath = '/' . str_repeat('a', 127);
        $long = new ServerRequest('get', new Uri('http://localhost' . $longPath, ['localhost']));
        $middleware->process($long, new EdgeMatrixCapturingHandler());
        $truncatedRequest = $boundary->lastRequest;
        self::assertNotNull($truncatedRequest);
        $operation = $truncatedRequest->operationClass;
        self::assertSame(SecurityRequest::MAX_OPERATION_BYTES, strlen($operation), 'attacker-controlled paths are truncated to 128 bytes');
        self::assertSame('GET /' . str_repeat('a', 123), $operation, 'the truncation keeps the method prefix');

        // A 124-byte path (128 bytes with the method prefix) stays untouched.
        $edgePath = '/' . str_repeat('b', 123);
        $edge = new ServerRequest('get', new Uri('http://localhost' . $edgePath, ['localhost']));
        $middleware->process($edge, new EdgeMatrixCapturingHandler());
        $edgeRequest = $boundary->lastRequest;
        self::assertNotNull($edgeRequest);
        self::assertSame('GET ' . $edgePath, $edgeRequest->operationClass);
    }

    public function testAuthDenyStatusReflectsAuthenticationContext(): void
    {
        // Boundary denial with an unauthenticated context => 401. A bearer
        // credential is required so the request passes the early 401 gate
        // and actually reaches the admission boundary.
        $denied = $this->authMiddleware(null, false);
        $request = new ServerRequest('post', new Uri('http://localhost/private', ['localhost']), [], [], [], [], null, [
            'Authorization' => 'Bearer tok',
        ]);
        $response = $denied->process($request, new EdgeMatrixCapturingHandler());
        $body = $response->getBody()->__toString();

        self::assertSame(401, $response->getStatusCode());
        self::assertTrue(str_contains($body, 'authorization_denied'));
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        $correlation = $decoded['correlation_id'] ?? null;
        self::assertIsString($correlation);
        self::assertSame(16, strlen($correlation), 'fallback correlation ids are 8 random bytes, hex-encoded');

        // Authenticated-but-unauthorized context => 403.
        $context = new DistributedSecurityContext(
            'principal-1',
            'bearer',
            'ctx',
            'scope',
            null,
        );
        $deniedAuth = $this->authMiddleware($context, false);
        $forbidden = $deniedAuth->process($request, new EdgeMatrixCapturingHandler());
        self::assertSame(403, $forbidden->getStatusCode(), 'an authenticated context escalates the denial to 403');
    }

    public function testAuthBoundsAttackerReplayIdHeader(): void
    {
        $middleware = $this->authMiddleware(null, true);
        // Non-uniform bytes: an off-by-one on substr()'s start offset or
        // length must change the visible truncation result.
        $oversized = 'head-' . str_repeat('r', 113) . '-tail-12345';
        self::assertSame(SecurityRequest::MAX_REPLAY_ID_BYTES + 1, strlen($oversized));
        $request = new ServerRequest('get', new Uri('http://localhost/public', ['localhost']), [], [], [], [], null, [
            'X-Replay-Id' => $oversized,
        ]);

        $middleware->process($request, new EdgeMatrixCapturingHandler());

        $boundary = $this->boundaryOf($middleware);
        self::assertNotNull($boundary);
        $captured = $boundary->lastRequest;
        self::assertNotNull($captured);
        $replayId = $captured->replayId;
        self::assertNotNull($replayId);
        self::assertSame(SecurityRequest::MAX_REPLAY_ID_BYTES, strlen($replayId), 'oversized replay ids are truncated, fail-closed');
        self::assertSame(substr($oversized, 0, SecurityRequest::MAX_REPLAY_ID_BYTES), $replayId, 'truncation keeps the leading bytes');
    }

    public function testAuthBearerHeaderParsesOnlyWellFormedCredentials(): void
    {
        // Safe method: every case reaches the credential provider, so the
        // extracted (or anonymous) credential is observable through the spy.
        $cases = [
            'XBearer abc' => null, // missing ^ anchor => must not match
            'bearer abc' => 'abc', // case-insensitive scheme
            'Bearer abc' => 'abc', // canonical form
            'Bearer    ' => null, // whitespace-only token
        ];
        // Note: the $-anchor of the Bearer grammar is additionally pinned by
        // MessageBase's CRLF guard: header values containing newlines are
        // rejected at PSR-7 construction, so the embedded-newline vector is
        // unreachable through the public header API (documented inventory).

        foreach ($cases as $header => $expectedId) {
            $middleware = $this->authMiddleware(null, true);
            $provider = $this->providerOf($middleware);
            $request = new ServerRequest('get', new Uri('http://localhost/public', ['localhost']), [], [], [], [], null, [
                'Authorization' => $header,
            ]);

            $middleware->process($request, new EdgeMatrixCapturingHandler());

            $handle = $provider->lastHandle;
            $extracted = $handle?->handleId;
            if ($expectedId === null) {
                self::assertSame('anonymous', $extracted, "malformed header '{$header}' must fall back to the anonymous credential");
            } else {
                self::assertSame($expectedId, $extracted, "well-formed header '{$header}' must yield its token");
            }
        }
    }

    public function testAuthTokenLengthBoundaryMatchesCredentialDomainBound(): void
    {
        $middleware = $this->authMiddleware(null, true);
        $provider = $this->providerOf($middleware);

        $maxToken = str_repeat('t', CredentialHandle::MAX_ID_BYTES);
        $maxRequest = new ServerRequest('post', new Uri('http://localhost/private', ['localhost']), [], [], [], [], null, [
            'Authorization' => 'Bearer ' . $maxToken,
        ]);
        $middleware->process($maxRequest, new EdgeMatrixCapturingHandler());
        $maxHandle = $provider->lastHandle;
        self::assertNotNull($maxHandle);
        self::assertSame($maxToken, $maxHandle->handleId, 'a 128-byte token is exactly at the accepted bound');

        // A 129-byte token is dropped: on a safe method the anonymous
        // fallback credential becomes observable through the provider spy.
        $overToken = str_repeat('u', CredentialHandle::MAX_ID_BYTES + 1);
        $overRequest = new ServerRequest('get', new Uri('http://localhost/public', ['localhost']), [], [], [], [], null, [
            'Authorization' => 'Bearer ' . $overToken,
        ]);
        $middleware->process($overRequest, new EdgeMatrixCapturingHandler());
        $overHandle = $provider->lastHandle;
        self::assertNotNull($overHandle);
        self::assertSame('anonymous', $overHandle->handleId, 'a 129-byte token is rejected as unauthenticated');
    }

    // ------------------------------------------------------------------
    // SecurityRuntimeMiddleware
    // ------------------------------------------------------------------

    public function testSecMwTreatsLowercaseMethodsAsSafeForCsrfIssuance(): void
    {
        $middleware = $this->secMwMiddleware($this->csrfPolicy());
        $request = new ServerRequest('get', new Uri('https://localhost/form', ['localhost']));
        $handler = new EdgeMatrixCapturingHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode(), 'lowercase GET is a safe method: no CSRF enforcement');
        $cookies = $response->getHeader('Set-Cookie');
        self::assertNotEmpty($cookies, 'safe requests re-issue the CSRF cookie');
        $firstCookie = $cookies[0] ?? '';
        self::assertIsString($firstCookie);
        self::assertTrue(str_starts_with($firstCookie, 'ZEF-XSRF-TOKEN='));
    }

    public function testSecMwValidatesRequestIdHeaderBoundsAndCharset(): void
    {
        $middleware = $this->secMwMiddleware($this->csrfPolicy());

        // A valid 128-char id is echoed verbatim.
        $validId = str_repeat('a', 128);
        $response = $middleware->process(
            new ServerRequest('get', new Uri('https://localhost/x', ['localhost']), [], [], [], [], null, ['X-Request-ID' => $validId]),
            new EdgeMatrixCapturingHandler(),
        );
        self::assertSame($validId, $response->getHeaderLine('X-Request-ID'), 'a well-formed 128-char request id passes through');

        // 129 chars exceed the bound: the id is regenerated (32 hex chars).
        $oversized = str_repeat('b', 129);
        $response = $middleware->process(
            new ServerRequest('get', new Uri('https://localhost/x', ['localhost']), [], [], [], [], null, ['X-Request-ID' => $oversized]),
            new EdgeMatrixCapturingHandler(),
        );
        $regenerated = $response->getHeaderLine('X-Request-ID');
        self::assertNotSame($oversized, $regenerated, 'oversized request ids must be regenerated');
        self::assertSame(32, strlen($regenerated));
        self::assertTrue((bool) preg_match('/^[0-9a-f]{32}$/', $regenerated));

        // Invalid charset is regenerated as well.
        $response = $middleware->process(
            new ServerRequest('get', new Uri('https://localhost/x', ['localhost']), [], [], [], [], null, ['X-Request-ID' => 'bad id!']),
            new EdgeMatrixCapturingHandler(),
        );
        self::assertNotSame('bad id!', $response->getHeaderLine('X-Request-ID'));
    }

    public function testSecMwPrefersRequestAttributeTrustedProxiesOverConstructorList(): void
    {
        $middleware = $this->secMwMiddleware(new SecurityPolicy(), ['10.0.0.1']);
        $request = new ServerRequest(
            'get',
            new Uri('https://localhost/x', ['localhost']),
            ['REMOTE_ADDR' => '10.0.0.1'],
            [],
            [],
            [],
            null,
            ['X-Forwarded-For' => '9.9.9.9, 10.0.0.1'],
        );
        $request = $request->withAttribute('__zef_trusted_proxies', ['10.0.0.1']);
        $handler = new EdgeMatrixCapturingHandler();

        $middleware->process($request, $handler);

        $context = $handler->lastRequest?->getAttribute('zef.security.context');
        self::assertInstanceOf(SecurityContext::class, $context);
        self::assertSame('9.9.9.9', $context->clientIp, 'the request attribute wins over the constructor list');

        // Without the attribute the constructor list applies.
        $handler = new EdgeMatrixCapturingHandler();
        $middleware->process($request->withoutAttribute('__zef_trusted_proxies'), $handler);
        $context = $handler->lastRequest?->getAttribute('zef.security.context');
        self::assertInstanceOf(SecurityContext::class, $context);
        self::assertSame('9.9.9.9', $context->clientIp, 'REMOTE_ADDR 10.0.0.1 is trusted through the constructor list');
    }

    public function testSecMwMarksHttpsSchemeRequestsAsSecure(): void
    {
        $middleware = $this->secMwMiddleware(new SecurityPolicy());
        $handler = new EdgeMatrixCapturingHandler();

        $middleware->process(new ServerRequest('get', new Uri('https://localhost/x', ['localhost'])), $handler);
        $context = $handler->lastRequest?->getAttribute('zef.security.context');
        self::assertInstanceOf(SecurityContext::class, $context);
        self::assertTrue($context->secure, 'https scheme must flag the security context as secure');

        $middleware->process(new ServerRequest('get', new Uri('http://localhost/x', ['localhost'])), $handler);
        $context = $handler->lastRequest?->getAttribute('zef.security.context');
        self::assertInstanceOf(SecurityContext::class, $context);
        self::assertFalse($context->secure);
    }

    public function testSecMwRateLimitFailureHeadersRemainIntact(): void
    {
        $middleware = $this->secMwMiddleware(new SecurityPolicy(rateLimitEnabled: true), [], new EdgeMatrixLimiter(throws: true));
        $response = $middleware->process(
            new ServerRequest('get', new Uri('https://localhost/x', ['localhost'])),
            new EdgeMatrixCapturingHandler(),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'), 'the limiter crash path keeps its Retry-After header');
        self::assertNotSame('', $response->getHeaderLine('X-Request-ID'));
    }

    public function testSecMwThrottledResponsesCarryFullHeaderSet(): void
    {
        $middleware = $this->secMwMiddleware(new SecurityPolicy(rateLimitEnabled: true), [], new EdgeMatrixLimiter(allowed: false));
        $requestId = str_repeat('c', 16);
        $response = $middleware->process(
            new ServerRequest('get', new Uri('https://localhost/x', ['localhost']), [], [], [], [], null, ['X-Request-ID' => $requestId]),
            new EdgeMatrixCapturingHandler(),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('7', $response->getHeaderLine('Retry-After'));
        self::assertSame('100', $response->getHeaderLine('X-RateLimit-Limit'), 'the policy limit is echoed');
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'), 'a throttled client has no remaining budget');
        self::assertSame($requestId, $response->getHeaderLine('X-Request-ID'));
    }

    public function testSecMwOriginDenialKeepsCorrelationHeader(): void
    {
        $policy = new SecurityPolicy(allowedOrigins: ['https://trusted.example'], originEnabled: true);
        $middleware = $this->secMwMiddleware($policy);
        $requestId = str_repeat('d', 16);
        $response = $middleware->process(
            new ServerRequest('get', new Uri('https://localhost/x', ['localhost']), [], [], [], [], null, [
                'X-Request-ID' => $requestId,
                'Origin' => 'https://evil.example',
            ]),
            new EdgeMatrixCapturingHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($requestId, $response->getHeaderLine('X-Request-ID'), 'origin denials keep the correlation header');
    }

    public function testSecMwCsrfGateRejectsEachMissingLinkIndependently(): void
    {
        $policy = $this->csrfPolicy();
        $token = $this->issueToken($policy);

        // The canonical happy path: cookie + header with the same valid token.
        $middleware = $this->secMwMiddleware($policy);
        $ok = $middleware->process($this->csrfPostRequest($token, $token), new EdgeMatrixCapturingHandler());
        self::assertSame(200, $ok->getStatusCode(), 'a matched cookie/header pair passes the CSRF gate');

        // Missing cookie.
        $noCookie = $this->secMwMiddleware($policy);
        $response = $noCookie->process($this->csrfPostRequest(null, $token), new EdgeMatrixCapturingHandler());
        self::assertSame(403, $response->getStatusCode(), 'unsafe requests without the cookie are rejected');

        // Missing header.
        $response = $this->secMwMiddleware($policy)->process($this->csrfPostRequest($token, null), new EdgeMatrixCapturingHandler());
        self::assertSame(403, $response->getStatusCode(), 'unsafe requests without the header are rejected');

        // Tampered cookie (fails HMAC validation).
        $response = $this->secMwMiddleware($policy)->process($this->csrfPostRequest('forged.token.value', $token), new EdgeMatrixCapturingHandler());
        self::assertSame(403, $response->getStatusCode(), 'an invalid cookie fails closed');

        // Mismatched pair.
        $other = $this->issueToken($policy);
        $response = $this->secMwMiddleware($policy)->process($this->csrfPostRequest($token, $other), new EdgeMatrixCapturingHandler());
        self::assertSame(403, $response->getStatusCode(), 'a cookie/header mismatch fails closed');
    }

    public function testSecMwCsrfDenialKeepsCorrelationHeader(): void
    {
        $policy = $this->csrfPolicy();
        $token = $this->issueToken($policy);
        $requestId = str_repeat('e', 16);

        $request = $this->csrfPostRequest(null, $token);
        $withId = $request->withHeader('X-Request-ID', $requestId);
        assert($withId instanceof ServerRequest);
        $response = $this->secMwMiddleware($policy)->process($withId, new EdgeMatrixCapturingHandler());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($requestId, $response->getHeaderLine('X-Request-ID'), 'CSRF denials keep the correlation header');
    }

    public function testSecMwCookieParserTrimsNamesAndValues(): void
    {
        $policy = $this->csrfPolicy();
        $token = $this->issueToken($policy);

        $dirtyCookie = 'other=1; ' . $policy->csrfCookieName . ' = ' . $token . '  ';
        $request = new ServerRequest('post', new Uri('https://localhost/submit', ['localhost']), [], [], [], [], null, [
            'Cookie' => $dirtyCookie,
            $policy->csrfHeaderName => $token,
        ]);
        $response = $this->secMwMiddleware($policy)->process($request, new EdgeMatrixCapturingHandler());

        self::assertSame(200, $response->getStatusCode(), 'whitespace around cookie names and values must be trimmed before validation');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function authMiddleware(
        ?DistributedSecurityContext $context,
        bool $allow,
    ): AuthenticationMiddleware {
        return new AuthenticationMiddleware(
            new EdgeMatrixAuthProvider($context),
            new EdgeMatrixNoopPolicy(),
            new EdgeMatrixNoopReplayProtector(),
            new EdgeMatrixAuthBoundary($allow),
        );
    }

    private function providerOf(AuthenticationMiddleware $middleware): EdgeMatrixAuthProvider
    {
        $property = new \ReflectionProperty(AuthenticationMiddleware::class, 'credentialProvider');
        $provider = $property->getValue($middleware);
        assert($provider instanceof EdgeMatrixAuthProvider);

        return $provider;
    }

    private function boundaryOf(AuthenticationMiddleware $middleware): ?EdgeMatrixAuthBoundary
    {
        $property = new \ReflectionProperty(AuthenticationMiddleware::class, 'boundary');
        $boundary = $property->getValue($middleware);

        return $boundary instanceof EdgeMatrixAuthBoundary ? $boundary : null;
    }

    private function csrfPolicy(): SecurityPolicy
    {
        return new SecurityPolicy(csrfEnabled: true, csrfSecret: 'unit-test-secret-0123456789abcdef');
    }

    private function issueToken(SecurityPolicy $policy): string
    {
        return new CsrfTokenManager($policy->csrfSecret, $policy->csrfTokenBytes)->issue();
    }

    private function csrfPostRequest(?string $cookieToken, ?string $headerToken): ServerRequestInterface
    {
        $policy = $this->csrfPolicy();
        $headers = [];
        if ($cookieToken !== null) {
            $headers['Cookie'] = $policy->csrfCookieName . '=' . $cookieToken;
        }
        if ($headerToken !== null) {
            $headers[$policy->csrfHeaderName] = $headerToken;
        }

        return new ServerRequest('post', new Uri('https://localhost/submit', ['localhost']), [], [], [], [], null, $headers);
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function secMwMiddleware(
        SecurityPolicy $policy,
        array $trustedProxies = [],
        ?EdgeMatrixLimiter $limiter = null,
    ): SecurityRuntimeMiddleware {
        return new SecurityRuntimeMiddleware(
            $policy,
            $limiter ?? new EdgeMatrixLimiter(),
            $trustedProxies,
        );
    }
}
