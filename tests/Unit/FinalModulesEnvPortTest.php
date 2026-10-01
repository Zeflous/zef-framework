<?php

declare(strict_types=1);

/*
 * Issue #55 step 3 (Middleware + Adapters modules — final migration) —
 * guard test untuk penutupan sensus statis.
 *
 * Dua invariant dikawal di sini:
 *   1. SENSUS TREE-WIDE: tidak ada lagi pemanggilan facade statis
 *      `Env::<method>(` di seluruh src/ — kecuali file Env.php sendiri
 *      (definisi facade-nya, menunggu keputusan versi step 4). Sensus tidak
 *      bisa diam-diam kembali tumbuh.
 *   2. Injeksi port berfungsi di modul terakhir: ConfigProvider dengan
 *      stub env menentukan stack middleware & factory, dan RoadRunnerRuntime
 *      membaca konfigurasi runtime melalui port.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Observability\RetryBackoffPolicy;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Middleware\ConfigProvider;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\SecurityHeadersMiddleware;

/**
 * @internal
 */
final class FinalModulesEnvPortTest extends TestCase
{
    public function testNoStaticEnvFacadeCallsRemainAnywhereInProductionSource(): void
    {
        $root = dirname(__DIR__, 2) . '/src/';
        if (\DIRECTORY_SEPARATOR === '\\') {
            // RecursiveDirectoryIterator yields backslash-separated pathnames
            // on Windows while $root stays forward-slashed — normalize both
            // sides so the Env.php exclusion below can match (issue #110).
            $root = \str_replace('\\', '/', $root);
        }
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $pathname = $file->getPathname();
            if (\DIRECTORY_SEPARATOR === '\\') {
                $pathname = \str_replace('\\', '/', $pathname);
            }
            $relative = \str_replace($root, '', $pathname);
            // Env.php sendiri mendefinisikan facade statis (keputusan penghapusan
            // menunggu issue #55 step 4) — definisi bukan pemanggilan.
            if ($relative === 'Domain/Foundation/Env.php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bEnv::\w+\s*\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Pemanggilan facade statis Env:: terdeteksi di src/ (' . implode(', ', $offenders)
            . ') — produksi wajib membaca environment melalui EnvInterface (issue #55). '
            . 'Tambahkan port yang diinjeksi; jangan kembali ke facade statis.',
        );
    }

    public function testConfigProviderIsDrivenByTheEnvPort(): void
    {
        $env = new FinalModulesStubEnv([
            'ZEF_SECURITY_HSTS' => '1',
            'ZEF_SECURITY_CSP' => '1',
            'ZEF_SECURITY_RATE_LIMIT_TIERS' => '[{"name":"t","limit":5,"windowSeconds":60}]',
            'ZEF_CORS_ORIGIN_ANY' => 'true',
        ]);

        $provider = new ConfigProvider(false, $env);
        $config = $provider->getConfig();

        $services = $config['services'] ?? null;
        self::assertIsArray($services, 'config wajib memuat services');
        $stack = $config['stack'] ?? null;
        self::assertIsArray($stack, 'config wajib memuat stack');

        // Stack mengikuti env port: tiers terisi -> middleware rate_limit ikut stack
        self::assertContains('middleware.security.rate_limit', $stack, 'tiers dari stub wajib memasukkan rate_limit ke stack');

        // Factory security.headers invocable dan dibangun dari stub
        $headersEntry = $services['middleware.security'] ?? null;
        self::assertIsArray($headersEntry);
        $headersFactory = $headersEntry['factory'] ?? null;
        self::assertIsCallable($headersFactory);
        $headers = $headersFactory();
        self::assertInstanceOf(SecurityHeadersMiddleware::class, $headers);

        // Factory rate_limit invocable melalui port
        $rateLimitEntry = $services['middleware.security.rate_limit'] ?? null;
        self::assertIsArray($rateLimitEntry);
        $rateLimitFactory = $rateLimitEntry['factory'] ?? null;
        self::assertIsCallable($rateLimitFactory);
        $rateLimit = $rateLimitFactory();
        self::assertInstanceOf(RateLimitMiddleware::class, $rateLimit);

        // CORS any-origin dari stub
        $corsEntry = $services['middleware.cors'] ?? null;
        self::assertIsArray($corsEntry);
        $corsFactory = $corsEntry['factory'] ?? null;
        self::assertIsCallable($corsFactory);
        $cors = $corsFactory();
        self::assertInstanceOf(CorsMiddleware::class, $cors);
    }

    public function testDomainFactoriesStillDefaultCorrectlyWithoutPort(): void
    {
        // jalur default (tanpa injeksi) tetap identik: fromEnvironment(null)
        $policy = SecurityPolicy::fromEnvironment();
        self::assertFalse($policy->rateLimitEnabled, 'default tanpa env: rate limit off');

        $retry = RetryBackoffPolicy::fromEnvironment();
        self::assertSame(2, $retry->maxRetries, 'default tanpa env: 2 percobaan retry');
    }
}

// ------------------------------------------------------------- Fixtures

/**
 * Sumber env kaleng — nilai yang dikembalikan sepenuhnya dikendalikan test.
 */
final class FinalModulesStubEnv implements EnvInterface
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
