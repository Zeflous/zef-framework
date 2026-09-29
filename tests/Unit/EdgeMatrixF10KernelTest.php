<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Application;
use Zef\Framework\Cache\CacheClockInterface;
use Zef\Framework\Cache\CacheInterface;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\CQRS\CommandBus;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\CQRS\QueryBus;
use Zef\Framework\Event\EventBusInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Observability\MeterInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryLogger;
use Zef\Framework\Observability\TracerInterface;
use Zef\Framework\Policy\ArchitecturePolicy;

/**
 * Fase 10 — kurikulum edge-case adversarial: Kernel\Application + boot lifecycle.
 * Setiap test menyebut mutator + baris target yang dibunuh.
 *
 * @internal
 */
final class EdgeMatrixF10KernelTest extends TestCase
{
    // ------------------------------------------------------------- Construction

    /** Application:59 FalseValue (debug default) + :81 Coalesce (logger injeksi). */
    public function testConstructorDefaultsAndLoggerInjection(): void
    {
        $app = new Application();
        $debug = new \ReflectionProperty(Application::class, 'debug');
        self::assertFalse($debug->getValue($app), 'debug wajib default false');

        $spy = new F10SpyLogger();
        $app2 = new Application(false, $spy);
        self::assertSame($spy, $app2->getContainer()->get(LoggerInterface::class), 'logger injeksi wajib dipakai, bukan NullLogger');
    }

    /** Application:66 Coalesce — ArchitecturePolicy injeksi wajib terpasang di container. */
    public function testInjectedArchitecturePolicyGovernsRegistrationBudget(): void
    {
        $policy = new ArchitecturePolicy(maxServiceRegistrations: 999);
        $app = new Application(false, null, null, $policy);
        $prop = new \ReflectionProperty($app->getContainer(), 'policy');
        self::assertSame($policy, $prop->getValue($app->getContainer()), 'policy injeksi wajib dipakai, bukan default baru');
    }

    /** Application:89 registerCacheServices + :492/#49-50 kapasitas + :494/:506 deps + :510 alias. */
    public function testCacheServiceGraphRegisteredAtConstruction(): void
    {
        $app = new Application();
        $c = $app->getContainer();
        self::assertInstanceOf(InMemoryCacheStore::class, $c->get(InMemoryCacheStore::class), 'cache services wajib terdaftar di konstruktor');
        self::assertInstanceOf(CacheInterface::class, $c->get(CacheInterface::class), 'alias CacheInterface wajib tersedia');

        $capacity = new \ReflectionProperty(InMemoryCacheStore::class, 'maxEntries');
        self::assertSame(10000, $capacity->getValue($c->get(InMemoryCacheStore::class)), 'kapasitas default store wajib 10000');

        $deps = $c->getRegistry()->definitions()[InMemoryCacheStore::class]->dependencies;
        self::assertSame([CacheClockInterface::class], $deps, 'deklarasi deps store eksak');
        $deps2 = $c->getRegistry()->definitions()[InMemoryCache::class]->dependencies;
        self::assertSame([InMemoryCacheStore::class], $deps2, 'deklarasi deps cache eksak');
    }

    /** Application:113-114 UnwrapArrayValues/ArrayMap/ArrayFilter — kanonisasi trusted hosts eksak (kunci list ketat). */
    public function testTrustedHostsCanonicalization(): void
    {
        $app = new Application();
        $app->setTrustedHosts(['a.example', '', '9' => 'b.example', 42]); // kunci 9 sengaja: tanpa tabrakan auto-index
        $prop = new \ReflectionProperty(Application::class, 'trustedHosts');
        $hosts = $prop->getValue($app);
        self::assertSame(['a.example', 'b.example', '42'], $hosts, 'hosts wajib dipetakan string, disaring kosong, dan reindex list');
        self::assertSame([0, 1, 2], \array_keys($hosts), 'kunci wajib list 0..2 tanpa celah');
    }

    /** Application:142 CastInt + :143 configurePolicies — nilai merged dari modul 'framework' wajib mengalir ke policy. */
    public function testBootAppliesMergedCrossModulePolicyValue(): void
    {
        $app = new Application();
        $app->addProvider(new F10FrameworkConfigProvider(['container' => ['max_cross_module_refs' => 3]]));
        $app->boot();
        $prop = new \ReflectionProperty($app->getContainer(), 'maxCrossModuleRefs');
        self::assertSame(3, $prop->getValue($app->getContainer()), 'nilai merged 3 wajib diterapkan ke container');
    }

    /** Application:142 CastInt — nilai non-numerik wajib jatuh ke 0 tanpa TypeError. */
    public function testBootCoercesNonNumericCrossModulePolicyValue(): void
    {
        $app = new Application();
        $app->addProvider(new F10FrameworkConfigProvider(['container' => ['max_cross_module_refs' => 'not-int']]));
        $app->boot();
        $prop = new \ReflectionProperty($app->getContainer(), 'maxCrossModuleRefs');
        self::assertSame(0, $prop->getValue($app->getContainer()), 'nilai non-numerik wajib jadi 0 tanpa TypeError');
    }

    /** Application:130 MethodCallRemoval — setMaxCrossModuleRefs wajib meneruskan ke container. */
    public function testSetMaxCrossModuleRefsAppliesToContainer(): void
    {
        $app = new Application();
        $app->setMaxCrossModuleRefs(7);
        $prop = new \ReflectionProperty($app->getContainer(), 'maxCrossModuleRefs');
        self::assertSame(7, $prop->getValue($app->getContainer()), 'limit wajib tersimpan di container');
    }

    /** Application:162/:163/:167 MethodCallRemoval — tiga bus wajib beku setelah boot. */
    public function testBootFreezesAllThreeBuses(): void
    {
        $app = $this->bootedProbeApp();
        $c = $app->getContainer();
        foreach ([EventBusInterface::class, CommandBus::class, QueryBus::class] as $id) {
            $bus = $c->get($id);

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $frozen = new \ReflectionProperty($bus, 'frozen');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertTrue($frozen->getValue($bus), "bus {$id} wajib beku setelah boot");
        }
    }

    /** Application:170 warmSingletons + :172 startAll + :105 modules->add — lifecycle boot eksak. */
    public function testBootWarmsSingletonsAndRunsModuleLifecycle(): void
    {
        $module = new F10LifecycleModule();
        $app = new Application();
        $app->addModule($module);
        $app->addProvider(new F10ConfigProvider([], [
            ['method' => 'GET', 'path' => '/f10/ok', 'handler' => 'f10.handler.ok', 'priority' => 10],
        ]));
        $app->addProvider(new F10HandlerProvider());
        $app->boot();

        self::assertSame(1, F10LifecycleModule::$bootCalls, 'boot module wajib sekali');
        self::assertSame(1, F10LifecycleModule::$startCalls, 'start module wajib sekali saat boot');
        self::assertSame(0, F10LifecycleModule::$shutdownCalls);
        self::assertTrue(F10LifecycleModule::$warmFactoryRan, 'singleton wajib di-warm saat boot, bukan lazy');
        self::assertSame(1, F10LifecycleModule::$warmFactoryRuns, 'warm tepat sekali');

        $response = $app->handle($this->request('/f10/ok'));
        self::assertSame(200, $response->getStatusCode(), 'route dari provider wajib terdaftar (modules->add bekerja)');
    }

    /** Application:269 LogicalOr + :270 ReturnRemoval + :273 shutdownAll — guard shutdown eksak. */
    public function testShutdownLifecycleGuards(): void
    {
        $module = new F10LifecycleModule();
        $app = new Application();
        $app->addModule($module);

        $app->shutdown(); // sebelum boot: no-op
        self::assertSame(0, F10LifecycleModule::$shutdownCalls, 'shutdown sebelum boot tidak boleh menyentuh modul');
        self::assertFalse((bool) new \ReflectionProperty(Application::class, 'shutdown')->getValue($app));

        $app->boot();
        $app->shutdown();
        self::assertSame(1, F10LifecycleModule::$shutdownCalls, 'shutdown pasca-boot wajib memanggil modul tepat sekali');
        $app->shutdown();
        self::assertSame(1, F10LifecycleModule::$shutdownCalls, 'shutdown kedua wajib idempoten');
    }

    // ------------------------------------------------------------- Handle

    /** Application:214 UnwrapStrToUpper — HEAD case-insensitive wajib mengosongkan body. */
    public function testHeadMethodIsCaseInsensitive(): void
    {
        $app = $this->bootedProbeApp();
        $response = $app->handle(new ServerRequest('head', new Uri('http://localhost/f10/ok', ['localhost'])));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody(), 'head (lowercase) wajib diperlakukan sebagai HEAD');
    }

    /** Application:299 PublicVisibility + :301 emitter->emit — emit publik wajib menulis body. */
    public function testEmitOutputsResponseBody(): void
    {
        $app = new Application();
        \ob_start();

        try {
            $app->emit(new Response(200, ['Content-Type' => 'text/plain'], 'f10-body'));
            $out = (string) \ob_get_clean();
        } catch (\Throwable $e) {
            \ob_end_clean();

            throw $e;
        }
        self::assertStringContainsString('f10-body', $out, 'emit wajib menulis body respons');
    }

    /** Application:369/:381/:389/:393/:404 — graf service observability terdaftar dengan deps eksak. */
    public function testObservabilityServiceGraph(): void
    {
        $app = new Application();
        $c = $app->getContainer();
        self::assertInstanceOf(TracerInterface::class, $c->get(TracerInterface::class));
        self::assertInstanceOf(MeterInterface::class, $c->get(MeterInterface::class));
        self::assertInstanceOf(TelemetryLogger::class, $c->get(TelemetryLogger::class));

        $defs = $c->getRegistry()->definitions();
        self::assertSame([Telemetry::class], $defs[TracerInterface::class]->dependencies);
        self::assertSame([Telemetry::class], $defs[MeterInterface::class]->dependencies);
        self::assertSame([LoggerInterface::class, Telemetry::class], $defs[TelemetryLogger::class]->dependencies);
    }

    /** Application:444 ±1 + :446 deps — TTL CommandBus 3600 dan deps eksak. */
    public function testCommandBusTtlAndDependencies(): void
    {
        $app = new Application();
        $c = $app->getContainer();
        $bus = $c->get(CommandBus::class);
        $ttl = new \ReflectionProperty(CommandBus::class, 'idempotencyTtlSeconds');

        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        self::assertSame(3600, $ttl->getValue($bus), 'TTL idempotensi CommandBus wajib 3600');
        $deps = $c->getRegistry()->definitions()[CommandBus::class]->dependencies;
        self::assertSame([InMemoryIdempotencyStore::class, EventBusInterface::class], $deps);
    }

    // ------------------------------------------------------------- OTLP session

    /** Application:195/:207/:211-221/:242-263/:519-523 — pipeline request penuh via OTLP sink. */
    public function testOtlpRequestPipelineSession(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl required for OTLP transport tests');
        }
        $buildDir = \dirname(__DIR__, 2) . '/build';
        if (!\is_dir($buildDir)) {
            \mkdir($buildDir, 0o777, true);
        }
        $sink = $buildDir . '/f10_otlp_sink_' . \uniqid('', true) . '.jsonl';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "cannot bind OTLP test server: {$errstr}");
        $name = (string) \stream_socket_get_name($server, false);
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);

        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            try {
                $deadline = \time() + 25;
                while (\time() < $deadline) {
                    $conn = @\stream_socket_accept($server, 3);
                    if ($conn === false) {
                        continue;
                    }
                    $raw = '';
                    while (($line = \fgets($conn)) !== false && $line !== "\r\n") {
                        $raw .= $line;
                    }
                    $body = '';
                    if (\preg_match('/Content-Length: (\d+)/i', $raw, $m) === 1) {
                        $body = (string) \stream_get_contents($conn, (int) $m[1]);
                    }
                    $path = (\preg_match('/^[A-Z]+ (\S+) HTTP/', $raw, $pm) === 1) ? $pm[1] : '/unknown';
                    \file_put_contents($sink, \json_encode(['path' => $path, 'raw' => $body]) . \PHP_EOL, \FILE_APPEND);
                    \fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
                    \fclose($conn);
                }
            } catch (\Throwable) {
            }
            \exit(0);
        }

        $backupEnv = $this->setOtlpEnv('http://127.0.0.1:' . $port);

        try {
            $app = $this->bootedProbeApp();
            $ok = $app->handle($this->request('/f10/ok', ['traceparent' => '00-11111111111111111111111111111111-2222222222222222-01']));
            self::assertSame(200, $ok->getStatusCode());

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertNotEmpty(\file_get_contents($sink) ?: '', 'flush per request wajib menulis sink segera');

            $err = null;

            try {
                $app->handle($this->request('/f10/fail', ['traceparent' => '00-33333333333333333333333333333333-4444444444444444-01']));
                self::fail('Expected exception to propagate from handler.');
            } catch (\RuntimeException $e) {
                $err = $e;
            }
            self::assertSame('f10-boom', $err->getMessage());
            $captured = F10ScopeProbeMiddleware::$captured;
            self::assertNotNull($captured, 'middleware probe wajib merekam request gagal');
            $scopeAttr = $captured->getAttribute('__zef_request_scope');
            self::assertNotNull($scopeAttr, 'request scope wajib tersedia sebagai atribut');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertTrue($scopeAttr->isClosed(), 'finally wajib menutup request scope meski handler melempar');

            $e500 = $app->handle($this->request('/f10/e500', ['traceparent' => '00-55555555555555555555555555555555-6666666666666666-01']));
            self::assertSame(500, $e500->getStatusCode());

            \usleep(300000);

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $lines = \array_values(\array_filter(\explode(\PHP_EOL, (string) \file_get_contents($sink))));
            self::assertNotEmpty($lines, 'sink wajib berisi payload OTLP');
            $payload = \array_map(
                static fn (string $l): array => (array) \json_decode($l, true),
                $lines,
            );

            $spans = [];
            foreach ($payload as $p) {
                if (($p['path'] ?? '') !== '/v1/traces') {
                    continue;
                }

                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                $decoded = \json_decode((string) ($p['raw'] ?? '{}'), true);

                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                foreach ($decoded['resourceSpans'] ?? [] as $rs) {
                    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                    foreach ($rs['scopeSpans'] ?? [] as $ss) {
                        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                        foreach ($ss['spans'] ?? [] as $sp) {
                            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                            if (($sp['name'] ?? '') === 'zef.http.request') {
                                $spans[] = $sp;
                            }
                        }
                    }
                }
            }
            self::assertGreaterThanOrEqual(3, \count($spans), 'tiga request wajib menghasilkan tiga span http (span wajib di-end di finally)');

            $byTrace = [];
            foreach ($spans as $sp) {
                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                $byTrace[(string) ($sp['traceId'] ?? '')] = $sp;
            }
            $okSpan = $byTrace['11111111111111111111111111111111'] ?? null;
            self::assertNotNull($okSpan, 'span 200 wajib ada');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $attrs = $this->spanAttributes($okSpan ?? []);
            self::assertSame('GET', $attrs['http.request.method'] ?? null, 'atribut http.request.method wajib ada di span');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertSame(200, (int) ($attrs['http.response.status_code'] ?? 0), 'atribut status_code wajib direkam');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $duration = (float) ($attrs['zef.request.duration_seconds'] ?? -1);
            self::assertGreaterThan(0.0, $duration, 'durasi wajib positif (aritmetika hrtime benar)');
            self::assertLessThan(60.0, $duration, 'durasi wajib dalam skala detik, bukan nanodetik');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertSame('STATUS_CODE_OK', $okSpan['status']['code'] ?? '', 'span 200 wajib berstatus OK');

            $e500Span = $byTrace['55555555555555555555555555555555'] ?? null;
            self::assertNotNull($e500Span, 'span 500 wajib ada');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertSame('STATUS_CODE_ERROR', $e500Span['status']['code'] ?? '', 'status >= 500 wajib ERROR');

            $failSpan = $byTrace['33333333333333333333333333333333'] ?? null;
            self::assertNotNull($failSpan, 'span request gagal wajib diekspor (finally wajib end span)');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertSame('STATUS_CODE_ERROR', $failSpan['status']['code'] ?? '', 'span gagal wajib ERROR');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            self::assertSame('RuntimeException', $failSpan['status']['message'] ?? '', 'deskripsi status wajib tipe eksepsi');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $events = $failSpan['events'] ?? [];

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $exceptionEvents = \array_values(\array_filter($events, static fn (array $ev): bool => ($ev['name'] ?? '') === 'exception'));
            self::assertNotEmpty($exceptionEvents, 'event exception wajib direkam pada span gagal');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $eventAttrs = $this->spanAttributes($exceptionEvents[0]);
            self::assertSame('RuntimeException', $eventAttrs['exception.type'] ?? null, 'exception.type wajib direkam');
            self::assertSame('f10-boom', $eventAttrs['exception.message'] ?? null, 'exception.message wajib direkam');

            $logRecords = [];
            foreach ($payload as $p) {
                if (($p['path'] ?? '') !== '/v1/logs') {
                    continue;
                }

                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                $decoded = \json_decode((string) ($p['raw'] ?? '{}'), true);

                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                foreach ($decoded['resourceLogs'] ?? [] as $rl) {
                    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                    foreach ($rl['scopeLogs'] ?? [] as $sl) {
                        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                        foreach ($sl['logRecords'] ?? [] as $lr) {
                            $logRecords[] = $lr;
                        }
                    }
                }
            }
            $startedAttrs = null;
            foreach ($logRecords as $lr) {
                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                $a = $this->spanAttributes($lr);
                if (($a['event.name'] ?? '') === 'request.started') {
                    $startedAttrs = $a;

                    break;
                }
            }
            self::assertNotNull($startedAttrs, 'lifecycle log request.started wajib terekspor');
            self::assertSame('11111111111111111111111111111111', $startedAttrs['trace_id'] ?? '', 'trace_id lifecycle wajib dari span context yang valid');
        } finally {
            $this->restoreOtlpEnv($backupEnv);
            \pcntl_waitpid($pid, $status);
            // Teardown of $sink, a name this test computed itself at line 248
            // ($buildDir . '/f10_otlp_sink_' . \uniqid('', true) . '.jsonl'), in the
            // finally block. No request input reaches the argument. Accepted
            // suppression: docs/security/php-sast.md section 7.3.
            @unlink($sink); // nosemgrep: unlink-use
            @\fclose($server);
        }
    }

    // ------------------------------------------------------------- Helpers

    private function bootedProbeApp(): Application
    {
        $app = new Application();
        $app->addProvider(new F10ConfigProvider([], [
            ['method' => 'GET', 'path' => '/f10/ok', 'handler' => 'f10.handler.ok', 'priority' => 10],
            ['method' => 'GET', 'path' => '/f10/fail', 'handler' => 'f10.handler.fail', 'priority' => 10],
            ['method' => 'GET', 'path' => '/f10/e500', 'handler' => 'f10.handler.e500', 'priority' => 10],
        ]));
        $app->addProvider(new F10HandlerProvider());
        $app->boot();

        return $app;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    private function request(string $path, array $attributes = []): ServerRequest
    {
        $request = new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']));
        foreach ($attributes as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        return $request;
    }

    /** @return array<string,mixed> */

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    private function spanAttributes(array $node): array
    {
        $out = [];

        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        foreach ($node['attributes'] ?? [] as $attr) {
            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $key = (string) ($attr['key'] ?? '');

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            $value = $attr['value'] ?? [];
            foreach (['stringValue', 'intValue', 'doubleValue', 'boolValue'] as $kind) {
                // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
                if (\array_key_exists($kind, $value)) {
                    $out[$key] = $value[$kind];

                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string,null|string> */
    private function setOtlpEnv(string $endpoint): array
    {
        $backup = [
            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            'ZEF_OTEL_ENABLED' => \getenv('ZEF_OTEL_ENABLED') ?: null,

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            'ZEF_OTEL_EXPORTER_OTLP_ENDPOINT' => \getenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT') ?: null,

            // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
            'ZEF_OTEL_FLUSH_PER_REQUEST' => \getenv('ZEF_OTEL_FLUSH_PER_REQUEST') ?: null,
        ];
        \putenv('ZEF_OTEL_ENABLED=true');
        \putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=' . $endpoint);
        \putenv('ZEF_OTEL_FLUSH_PER_REQUEST=true');

        return $backup;
    }

    /** @param array<string,null|string> $backup */
    private function restoreOtlpEnv(array $backup): void
    {
        foreach ($backup as $key => $value) {
            if ($value === null) {
                \putenv($key);
            } else {
                \putenv($key . '=' . $value);
            }
        }
    }
}

// ------------------------------------------------------------- Fixtures

final class F10FrameworkConfigProvider implements ConfigProviderInterface
{
    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function __construct(private readonly array $config) {}

    public function getModuleName(): string
    {
        return 'framework';
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function getConfig(): array
    {
        return $this->config;
    }
}

final class F10SpyLogger implements LoggerInterface
{
    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public array $lines = [];

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'emergency: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'alert: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'critical: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'error: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'warning: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'notice: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'info: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = 'debug: ' . $message;
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
        $this->lines[] = $level . ': ' . $message;
    }
}

final class F10ConfigProvider implements ConfigProviderInterface
{
    /** @param list<array<string,mixed>> $routes */

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function __construct(
        private readonly array $config,
        private readonly array $routes,
    ) {}

    public function getModuleName(): string
    {
        return 'f10probe';
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function getConfig(): array
    {
        return $this->config + ['routes' => $this->routes];
    }
}

final class F10HandlerProvider implements ConfigProviderInterface
{
    public function getModuleName(): string
    {
        return 'middleware';
    }

    // @phpstan-ignore-next-line — data refleksi/JSON tak-tiped disengaja di kurikulum edge-case
    public function getConfig(): array
    {
        return [
            'services' => [
                'f10.handler.ok' => [
                    'factory' => static fn (): F10OkHandler => new F10OkHandler(),
                    'deps' => [],
                ],
                'f10.handler.fail' => [
                    'factory' => static fn (): F10FailHandler => new F10FailHandler(),
                    'deps' => [],
                ],
                'f10.handler.e500' => [
                    'factory' => static fn (): F10ServerErrorHandler => new F10ServerErrorHandler(),
                    'deps' => [],
                ],
                'f10.probe.mw' => [
                    'factory' => static fn (): F10ScopeProbeMiddleware => new F10ScopeProbeMiddleware(),
                    'deps' => [],
                ],
            ],
            'stack' => ['f10.probe.mw'],
        ];
    }
}

final class F10ScopeProbeMiddleware implements MiddlewareInterface
{
    public static ?ServerRequestInterface $captured = null;

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$captured = $request;

        return $handler->handle($request);
    }
}

final class F10OkHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'f10-ok');
    }
}

final class F10FailHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new \RuntimeException('f10-boom');
    }
}

final class F10ServerErrorHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(500, ['Content-Type' => 'text/plain'], 'f10-server-error');
    }
}

final class F10LifecycleModule implements ModuleInterface
{
    public static int $bootCalls = 0;
    public static int $startCalls = 0;
    public static int $shutdownCalls = 0;
    public static int $warmFactoryRuns = 0;
    public static bool $warmFactoryRan = false;

    public function getName(): string
    {
        return 'f10lifecycle';
    }

    public function getDefinition(): ModuleDefinition
    {
        return ModuleDefinition::fromArray($this->getName(), [
            'services' => [
                'f10.warm' => [
                    'factory' => static function (): \stdClass {
                        ++self::$warmFactoryRuns;
                        self::$warmFactoryRan = true;

                        return new \stdClass();
                    },
                    'deps' => [],
                    'lifetime' => 'singleton',
                ],
            ],
        ]);
    }

    public function register(ModuleContext $context): void {}

    public function boot(ModuleContext $context): void
    {
        ++self::$bootCalls;
    }

    public function start(ModuleContext $context): void
    {
        ++self::$startCalls;
    }

    public function shutdown(ModuleContext $context): void
    {
        ++self::$shutdownCalls;
    }
}
