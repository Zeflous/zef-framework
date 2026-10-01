<?php

declare(strict_types=1);

/*
 * Audit #304 regression: unknown env values must never silently map to
 * false on security controls. readBool() (filter_var without
 * FILTER_NULL_ON_FAILURE) turned 'enabled' into FALSE — one typo disabled
 * rate limiting, dropped HttpOnly from the CSRF cookie, switched the
 * origin policy off. All security booleans now flow through strict,
 * fail-closed parsing (readBoolStrict / SecurityPolicy::envBool).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityPolicyException;
use Zef\Middleware\ConfigProvider;
use Zef\Middleware\SecurityHeadersMiddleware;

/**
 * @internal
 */
final class Audit304StrictSecurityBooleansTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_AUDIT304_PROBE');
    }
    // ------------------------------------------------------------------
    // Env::readBoolStrict — the shared port-level primitive
    // ------------------------------------------------------------------

    public function testReadBoolStrictTruthTable(): void
    {
        $env = new Env();
        $truthy = ['1', 'true', 'yes', 'on', 'enabled', 'TRUE', 'Enabled'];
        foreach ($truthy as $value) {
            putenv("ZEF_AUDIT304_PROBE={$value}");
            self::assertTrue($env->readBoolStrict('ZEF_AUDIT304_PROBE'), " '{$value}' must be true");
        }
        $falsy = ['0', 'false', 'no', 'off', 'disabled', 'FALSE', 'Disabled'];
        foreach ($falsy as $value) {
            putenv("ZEF_AUDIT304_PROBE={$value}");
            self::assertFalse($env->readBoolStrict('ZEF_AUDIT304_PROBE'), " '{$value}' must be false");
        }
    }

    public function testReadBoolStrictFallsBackToDefaultWhenUnsetOrBlank(): void
    {
        $env = new Env();
        putenv('ZEF_AUDIT304_PROBE');

        self::assertTrue($env->readBoolStrict('ZEF_AUDIT304_PROBE', true), 'unset falls back to the secure default');
        putenv('ZEF_AUDIT304_PROBE=   ');
        self::assertTrue($env->readBoolStrict('ZEF_AUDIT304_PROBE', true), 'blank falls back to the secure default');
    }

    public function testReadBoolStrictThrowsOnUnrecognizedValue(): void
    {
        $env = new Env();
        putenv('ZEF_AUDIT304_PROBE=tru');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ZEF_AUDIT304_PROBE');
        $env->readBoolStrict('ZEF_AUDIT304_PROBE');
    }

    // ------------------------------------------------------------------
    // SecurityPolicy::fromEnvironment — every security boolean is strict
    // ------------------------------------------------------------------

    public function testRateLimitEnabledAcceptsEnabledSpelling(): void
    {
        $policy = SecurityPolicy::fromEnvironment(env: new Audit304Env([
            'ZEF_SECURITY_RATE_LIMIT' => 'enabled',
        ]));

        self::assertTrue($policy->rateLimitEnabled, 'the old lenient parser mapped enabled -> false (control off)');
    }

    public function testCsrfCookieFlagsAcceptEnabledSpelling(): void
    {
        $policy = SecurityPolicy::fromEnvironment(env: new Audit304Env([
            'ZEF_SECURITY_CSRF' => '1',
            'ZEF_SECURITY_CSRF_SECRET' => str_repeat('k', 32),
            'ZEF_SECURITY_CSRF_HTTP_ONLY' => 'enabled',
            'ZEF_SECURITY_CSRF_SECURE' => 'on',
        ]));

        self::assertTrue($policy->csrfHttpOnlyCookie, 'enabled must keep HttpOnly, not silently drop it');
        self::assertTrue($policy->csrfSecureCookie);
    }

    public function testOriginPolicyAcceptsOnSpelling(): void
    {
        $policy = SecurityPolicy::fromEnvironment(env: new Audit304Env([
            'ZEF_SECURITY_ORIGIN_POLICY' => 'on',
            'ZEF_SECURITY_ALLOWED_ORIGINS' => 'https://app.example',
        ]));

        self::assertTrue($policy->originEnabled, 'the old lenient parser mapped on -> false (policy off)');
    }

    public function testTypoOnAnySecurityBooleanRefusesToBoot(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessage('ZEF_SECURITY_RATE_LIMIT');

        SecurityPolicy::fromEnvironment(env: new Audit304Env([
            'ZEF_SECURITY_RATE_LIMIT' => 'tru', // one-character typo
        ]));
    }

    // ------------------------------------------------------------------
    // Live middleware wiring — src/Middleware/ConfigProvider (the app/
    // copy is dead: classmap + PSR-4 both resolve Zef\Middleware\* to src/)
    // ------------------------------------------------------------------

    public function testLiveConfigProviderHstsCspAreStrict(): void
    {
        $provider = new ConfigProvider(false, new Audit304Env([
            'ZEF_SECURITY_HSTS' => 'enabled',
            'ZEF_SECURITY_CSP' => 'on',
        ]));
        $factory = $this->securityHeadersFactory($provider);

        self::assertInstanceOf(SecurityHeadersMiddleware::class, $factory());
    }

    public function testLiveConfigProviderHstsTypoRefusesToBoot(): void
    {
        $provider = new ConfigProvider(false, new Audit304Env([
            'ZEF_SECURITY_HSTS' => 'enabledx', // one-character typo
        ]));
        $factory = $this->securityHeadersFactory($provider);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ZEF_SECURITY_HSTS');

        $factory();
    }

    /** @return callable(): SecurityHeadersMiddleware */
    private function securityHeadersFactory(ConfigProvider $provider): callable
    {
        $config = $provider->getConfig();
        $services = $config['services'] ?? null;
        if (!is_array($services)) {
            self::fail('getConfig() must carry a services map');
        }
        $security = $services['middleware.security'] ?? null;
        if (!is_array($security)) {
            self::fail('services must carry middleware.security');
        }
        $factory = $security['factory'] ?? null;
        if (!is_callable($factory)) {
            self::fail('middleware.security must expose a callable factory');
        }

        return $factory;
    }
}

/**
 * @internal
 */
final class Audit304Env implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = []) {}

    #[\Override]
    public function readInt(string $name, int $default, int $min, int $max, bool $strict = false): int
    {
        return $default;
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

        throw new \InvalidArgumentException($name . "='{$raw}' is not a recognized boolean.");
    }

    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        $raw = $this->values[$name] ?? null;

        return ($raw === null || $raw === '') ? $default : $raw;
    }

    #[\Override]
    public function readCsv(string $name): array
    {
        $raw = $this->values[$name] ?? '';

        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }
}
