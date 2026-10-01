<?php

declare(strict_types=1);

/*
 * Issue #55 step 3 (Domain module) — guard test untuk migrasi env-port
 * SecurityPolicy + RetryBackoffPolicy.
 *
 * Dua invariant dikawal di sini:
 *   1. fromEnvironment kedua kelas membaca SELURUH knob melalui
 *      EnvInterface — stub env kaleng sepenuhnya menentukan policy yang
 *      dihasilkan (bukti injeksi port, bukan facade statis / getenv langsung).
 *   2. File Domain yang dimigrasi bebas panggilan facade statis Env:: —
 *      sensus statis menurun monoton per modul (acceptance issue #55).
 *
 * Termasuk semantik khusus yang dipertahankan saat porting: whitespace-only
 * ZEF_SECURITY_CSRF diperlakukan sama seperti unset (default true), dan
 * envPositiveInt menolak nilai non-digit (typo "1OO") ke default — bukan 0.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Observability\RetryBackoffPolicy;
use Zef\Framework\Security\SecurityPolicy;

/**
 * @internal
 */
final class DomainEnvPortTest extends TestCase
{
    public function testSecurityPolicyIsFullyDrivenByTheEnvPort(): void
    {
        $env = new DomainStubEnv([
            'ZEF_SECURITY_RATE_LIMIT' => '1',
            'ZEF_SECURITY_RATE_LIMIT_MAX' => '250',
            'ZEF_SECURITY_RATE_LIMIT_WINDOW' => '120',
            'ZEF_SECURITY_RATE_LIMIT_MAX_KEYS' => '5000',
            'ZEF_SECURITY_CSRF' => '0',
            'ZEF_SECURITY_CSRF_SECRET' => str_repeat('k', 32),
            'ZEF_SECURITY_CSRF_COOKIE' => 'Stub-XSRF',
            'ZEF_SECURITY_CSRF_HEADER' => 'X-Stub-Token',
            'ZEF_SECURITY_CSRF_SECURE' => 'false',
            'ZEF_SECURITY_CSRF_HTTP_ONLY' => 'false',
            'ZEF_SECURITY_CSRF_SAMESITE' => 'Lax',
            'ZEF_SECURITY_ALLOWED_ORIGINS' => 'https://a.test, https://b.test',
            'ZEF_SECURITY_ORIGIN_POLICY' => '1',
            'ZEF_SECURITY_CSRF_TOKEN_BYTES' => '48',
        ]);

        $policy = SecurityPolicy::fromEnvironment(null, $env);

        self::assertTrue($policy->rateLimitEnabled, 'ZEF_SECURITY_RATE_LIMIT wajib mengalir dari stub');
        self::assertSame(250, $policy->rateLimitMaxRequests);
        self::assertSame(120, $policy->rateLimitWindowSeconds);
        self::assertSame(5000, $policy->rateLimitMaxKeys);
        self::assertFalse($policy->csrfEnabled, 'ZEF_SECURITY_CSRF=0 wajib menonaktifkan csrf');
        self::assertSame('Stub-XSRF', $policy->csrfCookieName);
        self::assertSame('X-Stub-Token', $policy->csrfHeaderName);
        self::assertFalse($policy->csrfSecureCookie);
        self::assertFalse($policy->csrfHttpOnlyCookie);
        self::assertSame('Lax', $policy->csrfSameSite);
        self::assertSame(['https://a.test', 'https://b.test'], $policy->allowedOrigins);
        self::assertTrue($policy->originEnabled);
        self::assertSame(48, $policy->csrfTokenBytes);
    }

    public function testSecurityPolicyKeepsTheWhitespaceOnlyAndTypoSemantics(): void
    {
        // whitespace-only ZEF_SECURITY_CSRF diperlakukan seperti unset (default true);
        // secret disediakan agar jalur "secret kosong -> disable" tidak menutupi semantik ini
        $policy = SecurityPolicy::fromEnvironment(null, new DomainStubEnv([
            'ZEF_SECURITY_CSRF' => '   ',
            'ZEF_SECURITY_CSRF_SECRET' => str_repeat('k', 32),
        ]));
        self::assertTrue($policy->csrfEnabled, 'whitespace-only CSRF env wajib jatuh ke default (true)');

        // typo non-digit pada positive-int knob jatuh ke default, bukan 0
        $typo = SecurityPolicy::fromEnvironment(null, new DomainStubEnv([
            'ZEF_SECURITY_RATE_LIMIT' => '1',
            'ZEF_SECURITY_RATE_LIMIT_MAX' => '1OO',
        ]));
        self::assertSame(100, $typo->rateLimitMaxRequests, 'typo "1OO" wajib jatuh ke default 100, bukan 0');
    }

    public function testRetryBackoffPolicyIsFullyDrivenByTheEnvPort(): void
    {
        $env = new DomainStubEnv([
            'ZEF_OTEL_RETRY_ATTEMPTS' => '5',
            'ZEF_OTEL_RETRY_DELAY_MS' => '250',
            'ZEF_OTEL_RETRY_DELAY_CAP_MS' => '8000',
        ]);

        $policy = RetryBackoffPolicy::fromEnvironment($env);

        self::assertSame(5, $policy->maxRetries);
        self::assertSame(250, $policy->initialDelayMs);
        self::assertSame(8000, $policy->maxDelayMs);
    }

    public function testMigratedDomainFilesCarryNoStaticFacadeCalls(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/src/Domain/Security/SecurityPolicy.php',
            dirname(__DIR__, 2) . '/src/Domain/Observability/RetryBackoffPolicy.php',
        ];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'Env::',
                $source,
                basename($file) . ' tidak boleh memanggil facade statis Env:: lagi (issue #55 step 3 — migrasi Domain).',
            );
        }
    }
}

// ------------------------------------------------------------- Fixtures

/**
 * Sumber env kaleng — nilai yang dikembalikan sepenuhnya dikendalikan test.
 */
final class DomainStubEnv implements EnvInterface
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = []) {}

    #[\Override]
    public function readInt(string $name, int $default, int $min, int $max, bool $strict = false): int
    {
        $raw = $this->values[$name] ?? null;
        if ($raw === null) {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            if ($strict) {
                throw new \InvalidArgumentException($name . ' must be an integer.');
            }

            return $default;
        }

        return max($min, min($max, (int) $raw));
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

        throw new \InvalidArgumentException($name . "='" . $raw . "' is not a recognized boolean.");
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

        return $raw === ''
            ? []
            : array_values(array_filter(
                array_map(trim(...), explode(',', $raw)),
                static fn (string $v): bool => $v !== '',
            ));
    }
}
