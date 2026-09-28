<?php

declare(strict_types=1);

/*
 * Guild Action Item 2 follow-up (issue #37) — dua varian soak RoadRunner
 * yang melengkapi RuntimeSoakTest (varian default, tanpa telemetri):
 *
 *   1. Varian OTLP: ZEF_OTEL_ENABLED=true + ZEF_OTEL_FLUSH_PER_REQUEST=true
 *      + file-sink exporter (port OtlpExporterFactoryInterface di-decorate
 *      sebelum boot) — menekan jalur Telemetry::flush() +
 *      BatchSpanProcessor per-request di bawah beban berkelanjutan, persis
 *      tempat buffering exporter bisa bocor. Span kelas-kernel (request,
 *      routing, handler) benar-benar dibuat dan diekspor; sink file
 *      membuktikan batch export berjalan.
 *
 *   2. Varian Redis-store: ZEF_RATE_LIMIT_STORE=redis melawan server live
 *      di :6399 (CI provisioning docker Redis; skip anggun bila server
 *      tidak terjangkau) — memvalidasi reuse koneksi pconnect
 *      (RedisSharedRateLimitStore) tanpa pertumbuhan koneksi per-request.
 *      Hash rate-limit di Redis wajib terisi tepat TOTAL_REQUESTS — bukti
 *      setiap request menulis ke store melalui jalur produksi.
 *
 * Kedua varian memakai pola fixture soak yang sama dengan #33 (worker +
 * probe di ujung stack produksi, respons DIBUANG, ambang memori
 * terkalibrasi dengan margin terdokumentasi) dan allowlist static-state
 * tetap kosong (dikawal RuntimeSoakTest::testLongRunningLayersCarryNoStaticState).
 *
 * Kalibrasi ambang memori (margin seperti varian default, lihat
 * RuntimeSoakTest): +56 KiB used / +2 MiB arena untuk 3.000 request
 * pasca-warmup (~19 B/request); ambang 512 KiB / 8 MiB memberi margin 9x.
 * Varian OTLP menambah objek span per-request yang di-flush tiap request
 * (tidak menumpuk); varian Redis menambah satu EVAL Lua per-request tanpa
 * akumulasi sisi PHP.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\ResolutionContext;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\OtlpExporterFactoryInterface;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;
use Zef\Middleware\ConfigProvider;

/**
 * @internal
 */
final class RuntimeSoakVariantsTest extends TestCase
{
    public const int WARMUP_REQUESTS = 300;

    public const int TOTAL_REQUESTS = 3300;

    /** 512 KiB — kalibrasi: 56 KiB untuk 3.000 request pasca-warmup, margin 9x */
    private const int MAX_USED_GROWTH_BYTES = 524288;

    /** 8 MiB — arena allocator (granularitas chunk 2 MiB); kalibrasi: +2 MiB */
    private const int MAX_REAL_GROWTH_BYTES = 8388608;

    protected function tearDown(): void
    {
        putenv('ZEF_OTEL_ENABLED');
        putenv('ZEF_OTEL_FLUSH_PER_REQUEST');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('ZEF_SECURITY_RATE_LIMIT');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX');
        putenv('ZEF_SECURITY_CSRF_SECRET');
        putenv('ZEF_RATE_LIMIT_STORE');
        putenv('ZEF_REDIS_URL');
        // PHPUnit 10 default: backupStaticAttributes=false, runInSeparateProcess
        // tidak diaktifkan — static state probe WAJIB dibersihkan manual agar
        // tidak bocor ke test berikutnya dalam satu proses (review PR #82).
        OtlpSoakProbeMiddleware::reset();
        RedisSoakProbeMiddleware::reset();
    }

    /**
     * Varian 1 — soak dengan OTLP aktif + file-sink exporter:
     * exit code bersih, seluruh respons 200, memori terikat, DAN jalur
     * flush/export benar-benar berjalan (sink file berisi batch span).
     */
    public function testRoadRunnerOtlpEnabledSoakKeepsMemoryBounded(): void
    {
        $sink = tempnam(sys_get_temp_dir(), 'zef-otlp-sink-');
        self::assertNotFalse($sink);
        OtlpSoakProbeMiddleware::reset();

        putenv('ZEF_OTEL_ENABLED=true');
        putenv('ZEF_OTEL_FLUSH_PER_REQUEST=true');
        // URI absolut apa pun lolos validasi; file-sink factory mengabaikannya
        // (endpoint http://127.0.0.1:9 sengaja tidak pernah dihubungi).
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:9/collect');

        $app = new Application();
        $app->addProvider(new OtlpSoakStackProvider());
        $app->addProvider(new OtlpSoakRoutesProvider());
        // Port exporter di-decorate SEBELUM boot (freeze) — keputusan level
        // konfigurasi yang sama yang dipakai aplikasi produksi.
        $app->getContainer()->decorate(
            OtlpExporterFactoryInterface::class,
            static fn (ResolutionContext $ctx, mixed $inner): OtlpExporterFactoryInterface => new OtlpFileSinkExporterFactory($sink),
        );
        $app->boot();

        $worker = new OtlpSoakWorker();
        $runtime = new RoadRunnerRuntime(
            $app,
            $worker,
            self::TOTAL_REQUESTS,
            0,
            false,
        );

        try {
            $exit = $runtime->run();

            self::assertSame(0, $exit, 'soak OTLP wajib selesai dengan exit code 0');
            self::assertSame(self::TOTAL_REQUESTS, $runtime->handledRequests());
            self::assertSame(self::TOTAL_REQUESTS, $worker->responded, 'semua request wajib direspons');
            self::assertSame(0, $worker->nonOk, 'tidak ada respons non-200 selama soak OTLP');
            self::assertSame(0, $worker->errors, 'tidak ada error worker selama soak OTLP');

            $this->assertMemoryBounded(
                OtlpSoakProbeMiddleware::$baselineUsed,
                OtlpSoakProbeMiddleware::$finalUsed,
                OtlpSoakProbeMiddleware::$baselineReal,
                OtlpSoakProbeMiddleware::$finalReal,
            );

            // Jalur flush/export benar-benar berjalan: sink file berisi
            // batch JSON, dan jumlah span total >= jumlah request (2-3
            // span/request: request + routing [+ handler]).
            $lines = array_values(array_filter(
                explode("\n", (string) file_get_contents($sink)),
                static fn (string $line): bool => trim($line) !== '',
            ));
            self::assertNotEmpty($lines, 'sink file wajib berisi minimal satu batch export');
            $spans = 0;
            foreach ($lines as $line) {
                $decoded = json_decode($line, true, 6);
                self::assertIsArray($decoded, 'setiap baris sink wajib JSON valid');
                self::assertArrayHasKey('kind', $decoded);
                self::assertArrayHasKey('count', $decoded);
                if ($decoded['kind'] === 'spans') {
                    $count = $decoded['count'];
                    self::assertIsInt($count);
                    $spans += $count;
                }
            }
            self::assertGreaterThanOrEqual(
                self::TOTAL_REQUESTS,
                $spans,
                sprintf('total span terekspor (%d) wajib >= jumlah request (%d) — jalur BatchSpanProcessor+flush tidak berjalan penuh', $spans, self::TOTAL_REQUESTS),
            );
        } finally {
            @unlink($sink); // nosemgrep: php.lang.security.unlink-use — tempnam() sink the test itself created; finally teardown, no request superglobal in a PHPUnit process (§7.2 register)
        }
    }

    /**
     * Varian 2 — soak dengan rate-limit store Redis melawan server live:
     * exit code bersih, seluruh respons 200, memori terikat, DAN hash
     * rate-limit terisi tepat TOTAL_REQUESTS (bukti setiap request
     * menulis melalui koneksi pconnect yang sama).
     */
    public function testRoadRunnerRedisStoreSoakKeepsMemoryBounded(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension not available.');
        }
        $redis = new \Redis();
        // phpredis >= 6 throws RedisException from pconnect() instead of
        // returning false when the server is down (issue #213) — capture it so
        // the documented self-skip (INSTALLATION.md §8) stays true.
        $reachable = false;

        try {
            $reachable = $redis->pconnect('127.0.0.1', 6399, 2.0) && $redis->auth('zef-test-secret');
        } catch (\RedisException) {
            $reachable = false;
        }
        if (!$reachable) {
            self::markTestSkipped('Redis test server not reachable.');
        }
        $redis->select(0);
        $redis->flushDB();

        RedisSoakProbeMiddleware::reset();
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        // Ambang sangat longgar agar 3.300 request berurutan dari satu
        // klien tetap 200 — yang dikawal adalah jalur Redis-nya, bukan
        // keputusan limit-nya.
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX=1000000');
        putenv('ZEF_SECURITY_CSRF_SECRET=' . str_repeat('s', 32));
        putenv('ZEF_RATE_LIMIT_STORE=redis');
        putenv('ZEF_REDIS_URL=redis://:zef-test-secret@127.0.0.1:6399/0');

        $app = new Application();
        $app->addProvider(new RedisSoakStackProvider());
        $app->addProvider(new RedisSoakRoutesProvider());
        $app->boot();

        $worker = new RedisSoakWorker();
        $runtime = new RoadRunnerRuntime(
            $app,
            $worker,
            self::TOTAL_REQUESTS,
            0,
            false,
        );

        try {
            $exit = $runtime->run();

            self::assertSame(0, $exit, 'soak Redis wajib selesai dengan exit code 0');
            self::assertSame(self::TOTAL_REQUESTS, $runtime->handledRequests());
            self::assertSame(self::TOTAL_REQUESTS, $worker->responded, 'semua request wajib direspons');
            self::assertSame(0, $worker->nonOk, 'tidak ada respons non-200 selama soak Redis (limit sengaja dilonggarkan)');
            self::assertSame(0, $worker->errors, 'tidak ada error worker selama soak Redis');

            $this->assertMemoryBounded(
                RedisSoakProbeMiddleware::$baselineUsed,
                RedisSoakProbeMiddleware::$finalUsed,
                RedisSoakProbeMiddleware::$baselineReal,
                RedisSoakProbeMiddleware::$finalReal,
            );

            // Server tetap sehat dan koneksi reuse terjadi: satu hash
            // rate-limit dengan count tepat TOTAL_REQUESTS (setiap request
            // satu EVAL increment pada koneksi pconnect yang sama).
            self::assertTrue($redis->ping(), 'Redis wajib tetap responsif pasca-soak');
            $keys = $redis->keys('zef:ratelimit:*');
            self::assertSame(
                ['zef:ratelimit:' . hash('sha256', '127.0.0.1')],
                $keys,
                'wajib tepat satu bucket rate-limit untuk client 127.0.0.1',
            );
            $count = (int) $redis->hGet($keys[0], 'count');
            self::assertSame(
                self::TOTAL_REQUESTS,
                $count,
                sprintf('bucket rate-limit wajib terhitung tepat %d ( aktual %d) — jalur store Redis tidak berjalan per-request', self::TOTAL_REQUESTS, $count),
            );
        } finally {
            if ($redis->isConnected()) {
                $redis->flushDB();
            }
        }
    }

    // ------------------------------------------------------------- Helpers

    private function assertMemoryBounded(?int $baselineUsed, ?int $finalUsed, ?int $baselineReal, ?int $finalReal): void
    {
        self::assertNotNull($baselineUsed, 'snapshot memori warmup wajib terekam');
        self::assertNotNull($finalUsed, 'snapshot memori akhir wajib terekam');
        self::assertNotNull($baselineReal, 'snapshot arena warmup wajib terekam');
        self::assertNotNull($finalReal, 'snapshot arena akhir wajib terekam');

        $measured = self::TOTAL_REQUESTS - self::WARMUP_REQUESTS;
        $usedGrowth = $finalUsed - $baselineUsed;
        $realGrowth = $finalReal - $baselineReal;

        self::assertLessThanOrEqual(
            self::MAX_USED_GROWTH_BYTES,
            $usedGrowth,
            sprintf(
                'Kebocoran memori terdeteksi (varian soak): +%d byte (%.2f KiB) setelah %d request pasca-warmup '
                . '(ambang %d byte). Cari state yang terakumulasi per-request.',
                $usedGrowth,
                $usedGrowth / 1024,
                $measured,
                self::MAX_USED_GROWTH_BYTES,
            ),
        );
        self::assertLessThanOrEqual(
            self::MAX_REAL_GROWTH_BYTES,
            $realGrowth,
            sprintf(
                'Pertumbuhan arena allocator melebihi ambang (varian soak): +%d byte (%.2f MiB) setelah %d request '
                . '(ambang %d byte).',
                $realGrowth,
                $realGrowth / 1048576,
                $measured,
                self::MAX_REAL_GROWTH_BYTES,
            ),
        );
    }
}

// ------------------------------------------------------------- OTLP fixtures

final class OtlpSoakProbeMiddleware implements MiddlewareInterface
{
    public static int $handled = 0;

    public static ?int $baselineUsed = null;

    public static ?int $baselineReal = null;

    public static ?int $finalUsed = null;

    public static ?int $finalReal = null;

    public static function reset(): void
    {
        self::$handled = 0;
        self::$baselineUsed = null;
        self::$baselineReal = null;
        self::$finalUsed = null;
        self::$finalReal = null;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        ++self::$handled;
        if (self::$handled === RuntimeSoakVariantsTest::WARMUP_REQUESTS) {
            \gc_collect_cycles();
            self::$baselineUsed = \memory_get_usage(false);
            self::$baselineReal = \memory_get_usage(true);
        }
        if (self::$handled === RuntimeSoakVariantsTest::TOTAL_REQUESTS) {
            \gc_collect_cycles();
            self::$finalUsed = \memory_get_usage(false);
            self::$finalReal = \memory_get_usage(true);
        }

        return $handler->handle($request);
    }
}

/**
 * Stack produksi asli (ConfigProvider Middleware) + probe di ujung — sama
 * seperti varian default RuntimeSoakTest, menambah probe OTLP.
 */
final class OtlpSoakStackProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'middleware';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        /** @var array<string, mixed> $base */
        $base = new ConfigProvider()->getConfig();
        $services = $base['services'] ?? null;
        $stack = $base['stack'] ?? null;
        if (!\is_array($services) || !\is_array($stack)) {
            throw new \LogicException('Production middleware config must expose services+stack.');
        }

        $services['otlp.soak.probe.mw'] = [
            'factory' => static fn (): OtlpSoakProbeMiddleware => new OtlpSoakProbeMiddleware(),
            'deps' => [],
        ];
        $stack[] = 'otlp.soak.probe.mw';
        $base['services'] = $services;
        $base['stack'] = $stack;

        return $base;
    }
}

final class OtlpSoakRoutesProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'otlpsoak';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        return [
            'routes' => [
                ['method' => 'GET', 'path' => '/otlp-soak/a', 'handler' => 'otlpsoak.handler.ok', 'priority' => 10],
                ['method' => 'GET', 'path' => '/otlp-soak/b', 'handler' => 'otlpsoak.handler.ok', 'priority' => 10],
            ],
            'services' => [
                'otlpsoak.handler.ok' => [
                    'factory' => static fn (): OtlpSoakOkHandler => new OtlpSoakOkHandler(),
                    'deps' => [],
                ],
            ],
        ];
    }
}

final class OtlpSoakOkHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'otlp-soak-ok');
    }
}

final class OtlpSoakWorker implements WorkerInterface
{
    public int $responded = 0;

    public int $nonOk = 0;

    public int $errors = 0;

    private bool $running = true;

    private int $served = 0;

    #[\Override]
    public function waitRequest(): ServerRequestInterface
    {
        $paths = ['/otlp-soak/a', '/otlp-soak/b'];
        $path = $paths[$this->served % 2];
        ++$this->served;

        return new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']));
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        ++$this->responded;
        if ($response->getStatusCode() !== 200) {
            ++$this->nonOk;
        }
    }

    #[\Override]
    public function error(string $message): void
    {
        ++$this->errors;
    }

    #[\Override]
    public function stop(): void
    {
        $this->running = false;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }
}

// ------------------------------------------------------------- OTLP file sink

/**
 * File-sink exporter (fixture): menulis satu baris JSON per batch export.
 * Mengimplementasikan ketiga surface exporter seperti OtlpHttpJsonExporter,
 * tanpa I/O jaringan — deterministik untuk soak.
 */
final class OtlpFileSinkExporter implements LogExporterInterface, MetricExporterInterface, SpanExporterInterface
{
    public function __construct(private readonly string $sinkPath) {}

    /** @param list<mixed> $spans */
    #[\Override]
    public function export(array $spans): void
    {
        $this->append('spans', count($spans));
    }

    /** @param list<LogRecord> $records */
    #[\Override]
    public function exportLogs(array $records): void
    {
        $this->append('logs', count($records));
    }

    /** @param array<string,array{count:float|int,sum:float,attributes:array<string,mixed>}> $metrics */
    #[\Override]
    public function exportMetrics(array $metrics): void
    {
        $this->append('metrics', count($metrics));
    }

    #[\Override]
    public function shutdown(): void {}

    private function append(string $kind, int $count): void
    {
        if ($count < 1) {
            return;
        }
        @file_put_contents(
            $this->sinkPath,
            json_encode(['kind' => $kind, 'count' => $count], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND,
        );
    }
}

final class OtlpFileSinkExporterFactory implements OtlpExporterFactoryInterface
{
    public function __construct(private readonly string $sinkPath) {}

    #[\Override]
    public function create(
        string $endpoint,
        array $resource,
        int $timeoutMs,
    ): LogExporterInterface&MetricExporterInterface&SpanExporterInterface {
        return new OtlpFileSinkExporter($this->sinkPath);
    }
}

// ------------------------------------------------------------- Redis fixtures

final class RedisSoakProbeMiddleware implements MiddlewareInterface
{
    public static int $handled = 0;

    public static ?int $baselineUsed = null;

    public static ?int $baselineReal = null;

    public static ?int $finalUsed = null;

    public static ?int $finalReal = null;

    public static function reset(): void
    {
        self::$handled = 0;
        self::$baselineUsed = null;
        self::$baselineReal = null;
        self::$finalUsed = null;
        self::$finalReal = null;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        ++self::$handled;
        if (self::$handled === RuntimeSoakVariantsTest::WARMUP_REQUESTS) {
            \gc_collect_cycles();
            self::$baselineUsed = \memory_get_usage(false);
            self::$baselineReal = \memory_get_usage(true);
        }
        if (self::$handled === RuntimeSoakVariantsTest::TOTAL_REQUESTS) {
            \gc_collect_cycles();
            self::$finalUsed = \memory_get_usage(false);
            self::$finalReal = \memory_get_usage(true);
        }

        return $handler->handle($request);
    }
}

final class RedisSoakStackProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'middleware';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        /** @var array<string, mixed> $base */
        $base = new ConfigProvider()->getConfig();
        $services = $base['services'] ?? null;
        $stack = $base['stack'] ?? null;
        if (!\is_array($services) || !\is_array($stack)) {
            throw new \LogicException('Production middleware config must expose services+stack.');
        }

        $services['redis.soak.probe.mw'] = [
            'factory' => static fn (): RedisSoakProbeMiddleware => new RedisSoakProbeMiddleware(),
            'deps' => [],
        ];
        $stack[] = 'redis.soak.probe.mw';
        $base['services'] = $services;
        $base['stack'] = $stack;

        return $base;
    }
}

final class RedisSoakRoutesProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'redissoak';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        return [
            'routes' => [
                ['method' => 'GET', 'path' => '/redis-soak/a', 'handler' => 'redissoak.handler.ok', 'priority' => 10],
                ['method' => 'GET', 'path' => '/redis-soak/b', 'handler' => 'redissoak.handler.ok', 'priority' => 10],
            ],
            'services' => [
                'redissoak.handler.ok' => [
                    'factory' => static fn (): RedisSoakOkHandler => new RedisSoakOkHandler(),
                    'deps' => [],
                ],
            ],
        ];
    }
}

final class RedisSoakOkHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'redis-soak-ok');
    }
}

/**
 * Worker soak varian Redis — REMOTE_ADDR eksplisit agar clientIp
 * deterministik ('127.0.0.1') dan bucket rate-limit bisa diaudit
 * pasca-soak.
 */
final class RedisSoakWorker implements WorkerInterface
{
    public int $responded = 0;

    public int $nonOk = 0;

    public int $errors = 0;

    private bool $running = true;

    private int $served = 0;

    #[\Override]
    public function waitRequest(): ServerRequestInterface
    {
        $paths = ['/redis-soak/a', '/redis-soak/b'];
        $path = $paths[$this->served % 2];
        ++$this->served;

        return new ServerRequest(
            'GET',
            new Uri('http://localhost' . $path, ['localhost']),
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        ++$this->responded;
        if ($response->getStatusCode() !== 200) {
            ++$this->nonOk;
        }
    }

    #[\Override]
    public function error(string $message): void
    {
        ++$this->errors;
    }

    #[\Override]
    public function stop(): void
    {
        $this->running = false;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }
}
