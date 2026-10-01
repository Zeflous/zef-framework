<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;

/**
 * v2.31.0 — CSRF SPA Toolkit: strict fail-closed env booleans (audit C-11),
 * HMAC-bound token TTL (audit I-4), and the SPA-mode configuration guard
 * that refuses the HttpOnly double-submit trap (audit I-3).
 *
 * @internal
 */
final class CsrfSpaToolkitTest extends TestCase
{
    private const string SECRET = 'csrf-spa-toolkit-test-secret-0123456789abcdef';

    // -------------------------------------------------- strict env booleans (C-11)

    public function testTruthyEnvWordsEnableCsrfIncludingEnabled(): void
    {
        foreach (['1', 'true', 'yes', 'on', 'enabled', ' ENABLED '] as $word) {
            $policy = $this->policyFromEnv([
                'ZEF_SECURITY_CSRF' => $word,
                'ZEF_SECURITY_CSRF_SECRET' => self::SECRET,
            ]);
            self::assertTrue($policy->csrfEnabled, "'{$word}' must enable CSRF");
        }
    }

    public function testFalsyEnvWordsDisableCsrf(): void
    {
        foreach (['0', 'false', 'no', 'off', 'disabled'] as $word) {
            $policy = $this->policyFromEnv([
                'ZEF_SECURITY_CSRF' => $word,
                'ZEF_SECURITY_CSRF_SECRET' => self::SECRET,
            ]);
            self::assertFalse($policy->csrfEnabled, "'{$word}' must disable CSRF");
        }
    }

    public function testUnknownEnvBooleanRefusesToBoot(): void
    {
        // Before v2.31.0 this configuration silently DISABLED CSRF (C-11).
        try {
            $this->policyFromEnv([
                'ZEF_SECURITY_CSRF' => 'maybe',
                'ZEF_SECURITY_CSRF_SECRET' => self::SECRET,
            ]);
            self::fail('an unrecognized boolean must refuse to boot instead of failing open');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('not a recognized boolean', $e->getMessage());
        }
    }

    public function testExplicitEnabledWithoutSecretStillFailsLoudly(): void
    {
        try {
            $this->policyFromEnv(['ZEF_SECURITY_CSRF' => 'enabled']);
            self::fail('explicit CSRF enablement without a secret must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('requires ZEF_SECURITY_CSRF_SECRET', $e->getMessage());
        }
    }

    // -------------------------------------------------- token TTL (I-4)

    public function testTtlEnabledTokensCarryTimestampAndExpire(): void
    {
        $now = 1_700_000_000;
        $clock = static fn (): int => $now;
        $manager = new CsrfTokenManager(self::SECRET, 32, 3600, $clock);

        $token = $manager->issue();
        self::assertSame(3, substr_count($token, '.') + 1, 'TTL format: issuedAt.token.mac');
        self::assertTrue($manager->isValid($token), 'a fresh token must validate');

        $expiredToken = ($now - 3601) . '.' . explode('.', $token)[1] . '.' . explode('.', $token)[2];
        self::assertFalse($manager->isValid($expiredToken), 'a token past its TTL must be rejected');

        $futureToken = ($now + 5) . '.' . explode('.', $token)[1] . '.' . explode('.', $token)[2];
        self::assertFalse($manager->isValid($futureToken), 'future-dated stamps must be rejected');
    }

    public function testTamperedTtlTokenIsRejected(): void
    {
        $manager = new CsrfTokenManager(self::SECRET, 32, 3600);
        $token = $manager->issue();
        $parts = explode('.', $token);
        $parts[1] = 'tampered-token-value';
        $forged = $parts[0] . '.' . $parts[1] . '.' . $parts[2];

        self::assertFalse($manager->isValid($forged), 'the HMAC must bind the issuance timestamp to the token value');
    }

    public function testLegacyFormatRemainsUnchangedWithoutTtl(): void
    {
        $manager = new CsrfTokenManager(self::SECRET);
        $token = $manager->issue();

        self::assertSame(2, substr_count($token, '.') + 1, 'legacy format: token.mac');
        self::assertTrue($manager->isValid($token));
        self::assertFalse($manager->isValid($token . '.extra'), 'multi-part garbage must stay rejected');
    }

    public function testCrossFormatTokensAreRejected(): void
    {
        $legacy = new CsrfTokenManager(self::SECRET);
        $timed = new CsrfTokenManager(self::SECRET, 32, 3600);

        self::assertFalse($timed->isValid($legacy->issue()), 'a 2-part token cannot satisfy TTL verification');
        self::assertFalse($legacy->isValid($timed->issue()), 'a 3-part token cannot satisfy legacy verification');
    }

    public function testNegativeTtlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CsrfTokenManager(self::SECRET, 32, -1);
    }

    // -------------------------------------------------- SPA mode guard (I-3)

    public function testSpaModeWithHttpOnlyCookieIsRefused(): void
    {
        try {
            new SecurityPolicy(csrfEnabled: true, csrfSecret: self::SECRET, csrfSpaMode: true);
            self::fail('SPA mode with an HttpOnly double-submit cookie is a configuration trap and must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('JS-readable cookie', $e->getMessage());
        }
    }

    public function testSpaModeRequiresCsrfEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityPolicy(csrfEnabled: false, csrfSpaMode: true);
    }

    public function testSpaModeWithJsReadableCookieIsAccepted(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: true,
            csrfSecret: self::SECRET,
            csrfHttpOnlyCookie: false,
            csrfSameSite: 'Lax',
            csrfTokenTtlSeconds: 7200,
            csrfSpaMode: true,
        );

        self::assertTrue($policy->csrfSpaMode);
        self::assertFalse($policy->csrfHttpOnlyCookie);
        self::assertSame(7200, $policy->csrfTokenTtlSeconds);
    }

    public function testSpaModeWiresThroughEnvironment(): void
    {
        $policy = $this->policyFromEnv([
            'ZEF_SECURITY_CSRF' => '1',
            'ZEF_SECURITY_CSRF_SECRET' => self::SECRET,
            'ZEF_SECURITY_CSRF_HTTP_ONLY' => 'false',
            'ZEF_SECURITY_CSRF_SAMESITE' => 'Lax',
            'ZEF_SECURITY_CSRF_TTL' => '7200',
            'ZEF_SECURITY_CSRF_SPA' => '1',
        ]);

        self::assertTrue($policy->csrfSpaMode);
        self::assertFalse($policy->csrfHttpOnlyCookie);
        self::assertSame(7200, $policy->csrfTokenTtlSeconds);
    }

    // -------------------------------------------------- end-to-end SPA double-submit flow

    public function testSpaDoubleSubmitFlowWithJsReadableCookie(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: true,
            csrfSecret: self::SECRET,
            csrfHttpOnlyCookie: false,
            csrfSameSite: 'Lax',
            csrfTokenTtlSeconds: 7200,
            csrfSpaMode: true,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new NoopRateLimiterDouble());
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };

        // 1. A safe request hands the SPA a JS-readable token cookie.
        $bootstrap = $middleware->process($this->request('GET'), $handler);
        $setCookie = $bootstrap->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('ZEF-XSRF-TOKEN=', $setCookie);
        self::assertStringNotContainsString('HttpOnly', $setCookie, 'the SPA must be able to read the token (I-3)');
        self::assertStringContainsString('SameSite=Lax', $setCookie);

        // 2. The SPA echoes the cookie value in the header on unsafe requests.
        $token = trim(explode('ZEF-XSRF-TOKEN=', $setCookie, 2)[1] ?? '');
        $semicolon = strpos($token, ';');
        $token = $semicolon === false ? $token : substr($token, 0, $semicolon);
        $unsafe = $this->withCsrfHeader($this->postWithCookie($token), $token);
        self::assertSame(200, $middleware->process($unsafe, $handler)->getStatusCode());

        // 3. A mismatching header is still rejected.
        $mismatched = $this->withCsrfHeader($this->postWithCookie($token), 'other-token');
        self::assertSame(403, $middleware->process($mismatched, $handler)->getStatusCode());
    }

    private function postWithCookie(string $token): ServerRequestInterface
    {
        $withCookie = $this->request('POST')->withHeader('Cookie', 'ZEF-XSRF-TOKEN=' . $token);
        if (!$withCookie instanceof ServerRequestInterface) {
            throw new \LogicException('withHeader must preserve the request type.');
        }

        return $withCookie;
    }

    private function withCsrfHeader(ServerRequestInterface $request, string $token): ServerRequestInterface
    {
        $withHeader = $request->withHeader('X-CSRF-Token', $token);
        if (!$withHeader instanceof ServerRequestInterface) {
            throw new \LogicException('withHeader must preserve the request type.');
        }

        return $withHeader;
    }

    // -------------------------------------------------- harness

    /**
     * @param array<string, string> $values
     */
    private function policyFromEnv(array $values): SecurityPolicy
    {
        return SecurityPolicy::fromEnvironment(null, new ArrayEnvDouble($values));
    }

    private function request(string $method): ServerRequestInterface
    {
        return new ServerRequest($method, new Uri('http://localhost/app', ['localhost']));
    }
}

/**
 * Minimal EnvInterface double driven by an in-memory map.
 *
 * @internal
 */
final class ArrayEnvDouble implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(
        private readonly array $values = [],
    ) {}

    #[\Override]
    public function readInt(string $name, int $default, int $min, int $max, bool $strict = false): int
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            if ($strict) {
                throw new \InvalidArgumentException($name . ' must be an integer.');
            }

            return $default;
        }
        $value = (int) $raw;

        return max($min, min($max, $value));
    }

    #[\Override]
    public function readBool(string $name, bool $default = false): bool
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null || trim($raw) === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
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

        throw new \InvalidArgumentException($name . "='" . $raw . "' is not a recognized boolean.");
    }

    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        $raw = $this->values[$name] ?? null;

        return $raw ?? $default;
    }

    #[\Override]
    public function readCsv(string $name): array
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }
}

/**
 * @internal
 */
final class NoopRateLimiterDouble implements RateLimiterInterface
{
    #[\Override]
    public function check(string $identity, int $maxRequests, int $windowSeconds): RateLimitDecision
    {
        return new RateLimitDecision(true, $maxRequests, $maxRequests, 0, $windowSeconds);
    }
}
