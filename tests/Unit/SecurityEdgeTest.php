<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): security middleware edges —
 * authentication admission flow and runtime security headers/rate limits.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Router\Router;
use Zef\Framework\Security\AuthenticationMiddleware;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\CredentialProviderInterface;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\ReplayResult;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Middleware\SecurityHeadersMiddleware;

/**
 * @internal
 */
final class SecurityEdgeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX');
        putenv('ZEF_SECURITY_RATE_LIMIT_WINDOW');
    }

    public function testAuthenticationDeniesUnsafeAnonymousRequests(): void
    {
        $middleware = $this->authenticationMiddleware();
        $response = $middleware->process($this->request('/write', 'POST'), $this->passingHandler());
        self::assertSame(401, $response->getStatusCode());
    }

    public function testAuthenticationAllowsSafeAnonymousRequests(): void
    {
        $middleware = $this->authenticationMiddleware();
        $response = $middleware->process($this->request('/read', 'GET'), $this->passingHandler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('anonymous-001', $response->getHeaderLine('X-Principal'));
    }

    public function testAuthenticationRejectsPathsThatAliasAnAuthorizedResource(): void
    {
        $allowedPath = '/' . str_repeat('a', SecurityRequest::MAX_RESOURCE_BYTES - 1);
        $deniedPath = substr($allowedPath, 0, -1) . 'b';
        $router = new Router();
        $router->add('GET', $allowedPath, 'allowed');
        $router->add('GET', $deniedPath, 'denied');
        $router->add('GET', $allowedPath . 'b', 'private');
        $router->add('GET', $allowedPath . '/admin', 'admin');
        $policy = new class($allowedPath) implements AuthorizationPolicyInterface {
            /** @var list<string> */
            public array $resources = [];

            public function __construct(private readonly string $allowedPath) {}

            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                $this->resources[] = $request->resource;

                return new AuthorizationResult(
                    $request->resource === $this->allowedPath ? SecurityVerdict::ALLOW : SecurityVerdict::DENY,
                    'path-policy',
                );
            }
        };
        $handler = new class($router) implements RequestHandlerInterface {
            /** @var list<string> */
            public array $routes = [];

            public function __construct(private readonly Router $router) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
                $this->routes[] = $match['handler'];

                return new Response(200);
            }
        };
        $middleware = new AuthenticationMiddleware(
            $this->authenticatedProvider(),
            $policy,
            $this->acceptReplay(),
            new DefaultSecurityBoundary(),
        );
        $headers = ['Authorization' => 'Bearer token-abcdef'];
        self::assertSame(200, $middleware->process($this->request($allowedPath, 'GET', $headers), $handler)->getStatusCode());
        self::assertSame(403, $middleware->process($this->request($deniedPath, 'GET', $headers), $handler)->getStatusCode());

        foreach ([$allowedPath . 'b' => 'private', $allowedPath . '/admin' => 'admin'] as $path => $route) {
            self::assertSame($route, $router->match('GET', $path)['handler'], 'the full path resolves to a distinct protected route');
            $response = $middleware->process($this->request($path, 'GET', $headers), $handler);
            self::assertSame(414, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('correlation_id', $body);
        }

        self::assertSame([$allowedPath, $deniedPath], $policy->resources, 'oversized paths must not reach authorization');
        self::assertSame(['allowed'], $handler->routes, 'rejected requests must not reach routed handlers');
    }

    public function testAuthenticationAdmitsBearerCredentialsAndForwardsPrincipal(): void
    {
        $middleware = $this->authenticationMiddleware();
        $response = $middleware->process(
            $this->request('/write', 'POST', ['Authorization' => 'Bearer token-abcdef']),
            $this->passingHandler(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('principal-0001', $response->getHeaderLine('X-Principal'));
    }

    public function testAuthenticationTruncatesOversizedReplayIds(): void
    {
        $middleware = $this->authenticationMiddleware();
        $response = $middleware->process(
            $this->request('/write', 'POST', [
                'Authorization' => 'Bearer token-abcdef',
                'X-Replay-Id' => str_repeat('r', 500),
            ]),
            $this->passingHandler(),
        );
        self::assertSame(200, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // SecurityHeadersMiddleware
    // ------------------------------------------------------------------

    public function testSecurityHeadersMiddlewareAddsHardeningHeaders(): void
    {
        $middleware = new SecurityHeadersMiddleware(['hsts' => true, 'csp' => true]);
        $response = $middleware->process($this->request('/x'), $this->passingHandler());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        // HSTS is only emitted for https requests.
        $httpsRequest = new ServerRequest('GET', new Uri('https://localhost/x', ['localhost']));
        $httpsResponse = $middleware->process($httpsRequest, $this->passingHandler());
        self::assertStringContainsString('max-age=', $httpsResponse->getHeaderLine('Strict-Transport-Security'));
    }

    // ------------------------------------------------------------------
    // SecurityRuntimeMiddleware
    // ------------------------------------------------------------------

    public function testRuntimeMiddlewareEmitsSecurityHeadersAndKeepsState(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX=5');
        putenv('ZEF_SECURITY_RATE_LIMIT_WINDOW=60');
        $policy = SecurityPolicy::fromEnvironment();
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter($policy->rateLimitMaxKeys));
        $first = $middleware->process($this->request('/x'), $this->passingHandler());
        self::assertSame(200, $first->getStatusCode());
        self::assertNotSame('', $first->getHeaderLine('X-Request-ID'));

        $withId = $middleware->process(
            $this->request('/x', 'GET', ['X-Request-ID' => 'corr-000123']),
            $this->passingHandler(),
        );
        self::assertSame('corr-000123', $withId->getHeaderLine('X-Request-ID'));
    }

    public function testRuntimeMiddlewareReturns429WhenRateLimitExceeded(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX=1');
        putenv('ZEF_SECURITY_RATE_LIMIT_WINDOW=60');
        $policy = SecurityPolicy::fromEnvironment();
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter($policy->rateLimitMaxKeys));
        $middleware->process($this->request('/x'), $this->passingHandler());
        $blocked = $middleware->process($this->request('/x'), $this->passingHandler());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertNotSame('', $blocked->getHeaderLine('Retry-After'));
    }

    public function testRuntimeMiddlewareDeniesDisallowedOrigins(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        $policy = new SecurityPolicy(
            rateLimitEnabled: true,
            csrfEnabled: false,
            allowedOrigins: ['https://good.test'],
            originEnabled: true,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter($policy->rateLimitMaxKeys));
        $denied = $middleware->process(
            $this->request('/x', 'GET', ['Origin' => 'https://evil.test']),
            $this->passingHandler(),
        );
        self::assertSame(403, $denied->getStatusCode());

        $allowed = $middleware->process(
            $this->request('/x', 'GET', ['Origin' => 'https://good.test']),
            $this->passingHandler(),
        );
        self::assertSame(200, $allowed->getStatusCode());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path = '/', string $method = 'GET', array $headers = []): ServerRequestInterface
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']), [], [], [], [], null, $headers);
    }

    private function passingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $principal = $request->getAttribute('zef.security.principal', 'none');
                assert(is_string($principal));

                return new Response(200, ['X-Principal' => $principal], 'ok');
            }
        };
    }

    private function authenticatedProvider(): CredentialProviderInterface
    {
        return new class implements CredentialProviderInterface {
            #[\Override]
            public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
            {
                if ($handle->handleId === 'anonymous') {
                    return new AuthenticationResult(
                        AuthenticationStatus::AUTHENTICATED,
                        new SecurityContext('anonymous-001', 'public', 'tenant:guest', 'scope:none', null),
                    );
                }

                return new AuthenticationResult(
                    AuthenticationStatus::AUTHENTICATED,
                    new SecurityContext('principal-0001', 'bearer', 'tenant:acme', 'scope:read', null),
                );
            }
        };
    }

    private function allowAllAuthorization(): AuthorizationPolicyInterface
    {
        return new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(SecurityVerdict::ALLOW, 'policy.allow');
            }
        };
    }

    private function acceptReplay(): ReplayProtectorInterface
    {
        return new class implements ReplayProtectorInterface {
            #[\Override]
            public function check(?string $replayId, int $nowMs): ReplayResult
            {
                return new ReplayResult(ReplayDecision::ACCEPT);
            }
        };
    }

    private function authenticationMiddleware(): AuthenticationMiddleware
    {
        return new AuthenticationMiddleware(
            $this->authenticatedProvider(),
            $this->allowAllAuthorization(),
            $this->acceptReplay(),
            new DefaultSecurityBoundary(),
        );
    }
}
