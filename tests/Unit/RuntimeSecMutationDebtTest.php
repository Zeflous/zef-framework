<?php

declare(strict_types=1);

/*
 * ZEF Framework — issue #90 mutation debt burn-down, zone `ad-runtime-sec`
 * (round 2, after the round-1 `middleware` closure).
 *
 * Kill-map for the non-killed mutants measured on this branch (574 total,
 * 503 killed by the pre-existing suite, MSI 87.63 before this round; the
 * two redundant-production edits in this PR retire 3 further mutants):
 *
 *   AuthenticationMiddleware
 *     :45  Dec/Inc (epoch-ms multiplier) — testAuthResolveReceivesWallClockMilliseconds
 *     :66  Dec/Inc (epoch-ms multiplier) — testAuthAdmitReceivesWallClockMilliseconds
 *     Resource byte limit — testAuthPreservesResourcePathAtTheByteLimit
 *   RateLimitMiddleware
 *     :134/:138/:141 Concat + OperandRemoval (identity prefixes)
 *                                        — testRateLimitIdentityKeysAreStablePrefixContracts
 *   SecurityRuntimeMiddleware
 *     :68/:74/:87/:112 ArrayItemRemoval (correlation_id / reason bodies)
 *                                        — testSecurityRuntimeErrorBodiesCarryCorrelationAndReason
 *   BlockingSleeper
 *     :17 GreaterThan, :18 Dec/Inc        — testBlockingSleeperConvertsExactMicrosecondsAndSkipsZero
 *   RoadRunnerRuntime
 *     :106/:146 MethodCallRemoval (stop()) — testRuntimeStopsWorkerWhenPipeFails /
 *                                            testRuntimeStopsWorkerWhenMemoryBreachesAfterARequest
 *     :272-:275 scaling/threshold/rounding — testRuntimeSaturationTelemetryCarriesPreciseMemoryPercent
 *     NC :111 Break_, :114 x4, :116 Continue_, :254-:258 admit-reject branch
 *                                        — testRuntimeRejectsOverCapacityWithExact503AndKeepsDraining
 *     NC :223 LogicalNot, :303 ArrayItemRemoval — testRuntimeControlPlaneOnBootsWithoutFailingClosed
 *   RoadRunnerWorkerAdapter
 *     :66 LogicalAnd                      — testWorkerAdapterStopToleratesInnerWorkersWithoutStop
 *
 * Documented EQUIVALENTS no behavioural test can kill (kept as evidence):
 *   - AuthenticationMiddleware.php:94 PregMatchRemoveDollar — HeaderValidator
 *     rejects newline-containing header values before the regex can run, so
 *     no constructible request reaches it with a value where the trailing
 *     `$` anchor matters.
 *   - RateLimitMiddleware.php:74 UnwrapArrayFilter / UnwrapArrayValues —
 *     TrustedProxyMatcher casts every entry to string and foreach/in_array
 *     ignore keys, so non-string entries and preserved keys are inert.
 *   - RoadRunnerRuntime.php :98/:108/:148 Break_ — stop() sets
 *     stopRequested, so `continue` would exit through the loop condition on
 *     the very next check; the break is stylistic.
 *   - RoadRunnerRuntime.php CastInt on saturation_percent — the value is
 *     produced by EnvInterface::readInt, already an int.
 *   - RoadRunnerRuntime.php Increment on the private resourceCounters
 *     (rejected/saturation/completed) — the counters have no reader; the
 *     telemetry events asserted here carry the observable contract.
 *   - RoadRunnerRuntime.php :397 FalseValue — the runtime is single-use
 *     (run() refuses re-entry before signals could be consulted again).
 *   - RoadRunnerRuntime.php :225 Throw_ — validateControlCommand(
 *     'lifecycle.status') is true by construction (hardcoded allow-list),
 *     the throw is an unreachable fail-closed defense.
 *   - RoadRunnerRuntime.php validateControlCommand disabled-path mutants —
 *     its only caller short-circuits when the control plane is disabled.
 *   - TinkerSession render() catch-family — var_export() throws for no
 *     constructible PHP value (verified empirically: closures, anonymous
 *     classes, resources, generators, WeakMaps, Fibers, recursive arrays
 *     all export or warn); the '<unprintable:' branch is dead-defensive.
 *
 * The memory_get_usage/usleep namespace shadows (loaded once by the first
 * test below) make the runtime's clock/allocator reads deterministic; see
 * tests/Unit/runtime-sec-shadow-functions.php.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Runtime\BlockingSleeper;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\RoadRunnerWorkerAdapter;
use Zef\Framework\Runtime\WorkerInterface;
use Zef\Framework\Security\AuthenticationMiddleware;
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
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Security\TieredRateLimiter;

/**
 * @internal
 */
final class RuntimeSecMutationDebtTest extends TestCase
{
    private static bool $shadowsLoaded = false;

    /** @var array<string, false|string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Restore rather than unset: a variable that was already set when
        // this suite started must survive the run (coderabbit, PR #121).
        foreach (['ZEF_RUNTIME_CONTROL_PLANE', 'ZEF_RUNTIME_SATURATION_PERCENT', 'ZEF_OTEL_ENABLED'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        unset($GLOBALS['__zef_fake_memory'], $GLOBALS['__zef_usleep_calls']);
    }

    // ------------------------------------------------------------------
    // AuthenticationMiddleware — epoch-ms plumbing
    // ------------------------------------------------------------------

    /** Kills AuthenticationMiddleware.php:45 Dec/Inc: resolve() must receive the true wall-clock epoch in milliseconds. */
    public function testAuthResolveReceivesWallClockMilliseconds(): void
    {
        $this->loadShadows();
        $provider = new RuntimeSecRecordingAuthProvider();
        $middleware = $this->authMiddleware($provider, new RuntimeSecRecordingBoundary());

        $response = $middleware->process($this->request('/a', 'GET'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $provider->timestamps, 'resolve() must be called exactly once');
        $nowMs = (int) (microtime(true) * 1000);
        self::assertGreaterThanOrEqual($nowMs - 2500, $provider->timestamps[0], 'epoch-ms must track the real clock');
        self::assertLessThanOrEqual($nowMs + 2500, $provider->timestamps[0], 'epoch-ms must track the real clock');
    }

    /** Kills AuthenticationMiddleware.php:66 Dec/Inc: admit() must receive the true wall-clock epoch in milliseconds. */
    public function testAuthAdmitReceivesWallClockMilliseconds(): void
    {
        $this->loadShadows();
        $boundary = new RuntimeSecRecordingBoundary();
        $middleware = $this->authMiddleware(new RuntimeSecRecordingAuthProvider(), $boundary);

        $response = $middleware->process($this->request('/b', 'GET'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $boundary->timestamps, 'admit() must be called exactly once');
        $nowMs = (int) (microtime(true) * 1000);
        self::assertGreaterThanOrEqual($nowMs - 2500, $boundary->timestamps[0], 'epoch-ms must track the real clock');
        self::assertLessThanOrEqual($nowMs + 2500, $boundary->timestamps[0], 'epoch-ms must track the real clock');
    }

    public function testAuthPreservesResourcePathAtTheByteLimit(): void
    {
        $this->loadShadows();
        $boundary = new RuntimeSecRecordingBoundary();
        $middleware = $this->authMiddleware(new RuntimeSecRecordingAuthProvider(), $boundary);

        $path = '/' . str_repeat('x', SecurityRequest::MAX_RESOURCE_BYTES - 1);
        $middleware->process($this->request($path, 'GET'), $this->handler());

        $captured = $boundary->lastRequest;
        self::assertInstanceOf(SecurityRequest::class, $captured);
        self::assertSame($path, $captured->resource, 'the complete resource must reach authorization');
    }

    // ------------------------------------------------------------------
    // RateLimitMiddleware — identity key prefixes
    // ------------------------------------------------------------------

    /**
     * Kills RateLimitMiddleware.php:134/:138/:141 Concat + ConcatOperandRemoval:
     * the identity prefixes are a storage-key contract, asserted end-to-end
     * through TieredRateLimiter::storageKey (rule name + '>' + identity).
     */
    public function testRateLimitIdentityKeysAreStablePrefixContracts(): void
    {
        $this->loadShadows();
        $limiter = new RuntimeSecRecordingLimiter();
        $rule = new RateLimitRule('t', 60, 60);
        $middleware = new RateLimitMiddleware(new TieredRateLimiter($limiter, []), [$rule]);

        $byAttribute = $middleware->process(
            $this->request('/limited')->withAttribute('zef.auth.identity', 'user-1'),
            $this->handler(),
        );
        self::assertSame(200, $byAttribute->getStatusCode());

        $byApiKey = $middleware->process(
            $this->withHeader($this->request('/limited'), 'X-API-Key', 'k-1'),
            $this->handler(),
        );
        self::assertSame(200, $byApiKey->getStatusCode());

        $byIp = $middleware->process($this->request('/limited'), $this->handler());
        self::assertSame(200, $byIp->getStatusCode());

        self::assertSame(
            [
                't>identity:' . hash('sha256', 'user-1'),
                't>apikey:' . hash('sha256', 'k-1'),
                't>ip:0.0.0.0',
            ],
            $limiter->keys,
            'identity prefixes must LEAD the fingerprint and never swap or disappear',
        );
    }

    // ------------------------------------------------------------------
    // SecurityRuntimeMiddleware — error bodies carry their context
    // ------------------------------------------------------------------

    /** Kills SecurityRuntimeMiddleware.php:68/:74/:87/:112 ArrayItemRemoval: correlation_id and reason are part of the error contract. */
    public function testSecurityRuntimeErrorBodiesCarryCorrelationAndReason(): void
    {
        $this->loadShadows();

        $crashed = new SecurityRuntimeMiddleware(
            new SecurityPolicy(rateLimitEnabled: true, csrfEnabled: false),
            new RuntimeSecThrowingLimiter(),
        );
        $unavailable = $crashed->process(
            $this->withHeader($this->request('/x', 'GET'), 'X-Request-ID', 'req-123'),
            $this->handler(),
        );
        self::assertSame(503, $unavailable->getStatusCode());
        self::assertSame('req-123', $this->jsonField($unavailable, 'correlation_id'));

        $denied = new SecurityRuntimeMiddleware(
            new SecurityPolicy(rateLimitEnabled: true, csrfEnabled: false),
            new RuntimeSecRecordingLimiter(allowed: false),
        );
        $flooded = $denied->process(
            $this->withHeader($this->request('/x', 'GET'), 'X-Request-ID', 'req-456'),
            $this->handler(),
        );
        self::assertSame(429, $flooded->getStatusCode());
        self::assertSame('req-456', $this->jsonField($flooded, 'correlation_id'));

        $originGuarded = new SecurityRuntimeMiddleware(
            new SecurityPolicy(
                csrfEnabled: false,
                allowedOrigins: ['https://good.example'],
                originEnabled: true,
            ),
            new RuntimeSecRecordingLimiter(),
        );
        $originDenied = $originGuarded->process(
            $this->withHeader(
                $this->withHeader($this->request('/x', 'GET'), 'X-Request-ID', 'req-789'),
                'Origin',
                'https://evil.example',
            ),
            $this->handler(),
        );
        self::assertSame(403, $originDenied->getStatusCode());
        self::assertSame('Origin denied', $this->jsonField($originDenied, 'reason'));
        self::assertSame('req-789', $originDenied->getHeaderLine('X-Request-ID'), 'the request id survives as a response header');

        $csrfGuarded = new SecurityRuntimeMiddleware(
            new SecurityPolicy(csrfEnabled: true, csrfSecret: str_repeat('s', 32)),
            new RuntimeSecRecordingLimiter(),
        );
        $csrfDenied = $csrfGuarded->process($this->request('/x', 'POST'), $this->handler());
        self::assertSame(403, $csrfDenied->getStatusCode());
        self::assertSame('CSRF validation failed', $this->jsonField($csrfDenied, 'reason'));
        self::assertNotSame('', $csrfDenied->getHeaderLine('X-Request-ID'), 'the request id survives as a response header');
    }

    // ------------------------------------------------------------------
    // BlockingSleeper — microsecond conversion
    // ------------------------------------------------------------------

    /** Kills BlockingSleeper.php:17 GreaterThan and :18 Dec/Inc: ms-to-us conversion is exact and zero never sleeps. */
    public function testBlockingSleeperConvertsExactMicrosecondsAndSkipsZero(): void
    {
        $this->loadShadows();
        $GLOBALS['__zef_usleep_calls'] = [];

        BlockingSleeper::sleepMilliseconds(0);
        self::assertSame([], $GLOBALS['__zef_usleep_calls'], 'zero must not reach usleep at all');

        BlockingSleeper::sleepMilliseconds(50);
        self::assertSame([50000], $GLOBALS['__zef_usleep_calls'], '50 ms must convert to exactly 50000 us');

        BlockingSleeper::sleepMilliseconds(1);
        self::assertSame([50000, 1000], $GLOBALS['__zef_usleep_calls'], '1 ms must convert to exactly 1000 us');
    }

    // ------------------------------------------------------------------
    // RoadRunnerRuntime — stop() contracts, admission, saturation telemetry
    // ------------------------------------------------------------------

    /** Kills RoadRunnerRuntime.php:106 MethodCallRemoval: a pipe failure must stop the worker. */
    public function testRuntimeStopsWorkerWhenPipeFails(): void
    {
        $this->loadShadows();
        $worker = new RuntimeSecWorker([], waitFails: 'pipe gone');
        $runtime = new RoadRunnerRuntime($this->okApp(), $worker, 0, 0, false);

        $exit = $runtime->run();

        self::assertSame(1, $exit);
        self::assertSame(1, $worker->stopCalls, 'the worker must be stopped when its pipe fails');
        self::assertCount(1, $worker->errors, 'the failure must be reported exactly once');
    }

    /** Kills RoadRunnerRuntime.php:146 MethodCallRemoval: a post-request memory breach must stop the worker. */
    public function testRuntimeStopsWorkerWhenMemoryBreachesAfterARequest(): void
    {
        $this->loadShadows();
        $request = $this->request('/sec-ok', 'GET');
        $worker = new RuntimeSecWorker([$request], onWait: static function (): void {
            // Flip the shadowed allocator AFTER waitRequest() returns the
            // request, so the breach is observed by the post-handle check.
            $GLOBALS['__zef_fake_memory'] = ['fake' => 4];
        });
        $GLOBALS['__zef_fake_memory'] = ['fake' => 1];
        $runtime = new RoadRunnerRuntime($this->okApp(), $worker, 0, 2, false);

        $exit = $runtime->run();

        self::assertSame(2, $exit, 'memory breach exits with code 2');
        self::assertSame(1, $worker->stopCalls, 'the worker must be stopped when the memory limit breaches');
        self::assertCount(1, $worker->responses, 'the completed request still gets its response');
        self::assertSame([], $worker->errors);
    }

    /**
     * Kills RoadRunnerRuntime.php:272-:275 arithmetic family: the saturation
     * ratio scales usage/limit by 100, compares with >=, and rounds the
     * emitted memory.percent to exactly two decimals.
     */
    public function testRuntimeSaturationTelemetryCarriesPreciseMemoryPercent(): void
    {
        $this->loadShadows();
        putenv('ZEF_RUNTIME_SATURATION_PERCENT=50');
        // recordLog() no-ops on a disabled telemetry: the app's Telemetry is
        // composed from the environment, so arm the observability switch to
        // make the resource log records observable (exporters stay null —
        // nothing leaves the process).
        putenv('ZEF_OTEL_ENABLED=1');

        // Boundary: ratio lands exactly ON the threshold (>= must fire).
        self::assertSame(50.0, $this->saturatedMemoryPercent(memoryLimitBytes: 2, fakeBytes: 1), '1/2 must report exactly 50.00 percent');

        // Precision: 2/3 carries a repeating fraction that pins round(...,2).
        self::assertSame(66.67, $this->saturatedMemoryPercent(memoryLimitBytes: 3, fakeBytes: 2), '2/3 must report exactly 66.67 percent');

        // Full scale: usage == limit must report 100.00, not a scaled derivative.
        self::assertSame(100.0, $this->saturatedMemoryPercent(memoryLimitBytes: 2, fakeBytes: 2), 'usage == limit must report exactly 100.00 percent');
    }

    /**
     * Kills the previously-uncovered admit-reject branch (RoadRunnerRuntime
     * NC :111/:114 x4/:116/:254-:258): the 503 verdict, its exact JSON body
     * and header, the rejected telemetry event, and the continued drain.
     */
    public function testRuntimeRejectsOverCapacityWithExact503AndKeepsDraining(): void
    {
        $this->loadShadows();
        putenv('ZEF_OTEL_ENABLED=1');
        $app = $this->okApp();
        $worker = new RuntimeSecWorker([$this->request('/sec-ok', 'GET')]);
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        $inFlight = new \ReflectionProperty(RoadRunnerRuntime::class, 'inFlight');
        $inFlight->setValue($runtime, 5);

        $exit = $runtime->run();

        self::assertSame(0, $exit, 'a rejected request still drains cleanly');
        self::assertSame(2, $worker->waitCalls, 'the loop must CONTINUE draining after a 503, then stop on null');
        self::assertSame([], $worker->errors, 'no third poll may happen (the null break must exit the loop)');
        self::assertCount(1, $worker->responses, 'exactly one 503 response is sent');
        $rejected = $worker->responses[0];
        self::assertInstanceOf(ResponseInterface::class, $rejected);
        self::assertSame(503, $rejected->getStatusCode());
        self::assertSame('application/json', $rejected->getHeaderLine('Content-Type'));
        self::assertSame('{"error":"Service Unavailable","status":503}', (string) $rejected->getBody());
        self::assertGreaterThan(
            0,
            $this->meterCount($app, 'zef.runtime.resource.events.total', 'rejected'),
            'the rejected resource event must reach the meter',
        );
    }

    /** Kills the previously-uncovered control-plane check (RoadRunnerRuntime NC :223 LogicalNot and :303 ArrayItemRemoval for the lifecycle.status entry). */
    public function testRuntimeControlPlaneOnBootsWithoutFailingClosed(): void
    {
        $this->loadShadows();
        putenv('ZEF_RUNTIME_CONTROL_PLANE=on');
        $worker = new RuntimeSecWorker([]);
        $runtime = new RoadRunnerRuntime($this->okApp(), $worker, 0, 0, false);

        $exit = $runtime->run();

        self::assertSame(0, $exit, 'the enabled control plane must pass its own closed-boundary check');
        self::assertSame([], $worker->errors);
    }

    /**
     * Kills the previously-unreachable allow-list and disabled-path mutants
     * of validateControlCommand (RoadRunnerRuntime escaped :303
     * ArrayItemRemoval, NC :311 FalseValue + ReturnRemoval): every listed
     * command validates when enabled, everything else — including the whole
     * command space when disabled — fails closed.
     */
    public function testRuntimeValidateControlCommandFailsClosedOutsideItsAllowList(): void
    {
        $this->loadShadows();
        $method = new \ReflectionMethod(RoadRunnerRuntime::class, 'validateControlCommand');

        putenv('ZEF_RUNTIME_CONTROL_PLANE=on');
        $enabled = new RoadRunnerRuntime($this->okApp(), new RuntimeSecWorker([]), 0, 0, false);
        foreach (['diagnostics.snapshot', 'lifecycle.status', 'config.reload'] as $command) {
            self::assertTrue(
                $method->invoke($enabled, $command),
                "the enabled control plane must accept its allow-listed command '{$command}'",
            );
        }
        self::assertFalse(
            $method->invoke($enabled, 'diagnostics.purge'),
            'an unlisted command must fail closed even when the control plane is enabled',
        );

        putenv('ZEF_RUNTIME_CONTROL_PLANE');
        $disabled = new RoadRunnerRuntime($this->okApp(), new RuntimeSecWorker([]), 0, 0, false);
        self::assertFalse(
            $method->invoke($disabled, 'lifecycle.status'),
            'a disabled control plane must reject every command before consulting the allow-list',
        );
    }

    // ------------------------------------------------------------------
    // RoadRunnerWorkerAdapter — inner worker tolerance
    // ------------------------------------------------------------------

    /** Kills RoadRunnerWorkerAdapter.php:66 LogicalAnd: an inner worker without stop() must not be called. */
    public function testWorkerAdapterStopToleratesInnerWorkersWithoutStop(): void
    {
        $this->loadShadows();
        $outer = new class {
            public function waitRequest(): mixed
            {
                return null;
            }

            public function respond(ResponseInterface $response): void {}

            public function getWorker(): \stdClass
            {
                return new \stdClass();
            }
        };
        $adapter = new RoadRunnerWorkerAdapter($outer);

        try {
            $adapter->stop();
            self::addToAssertionCount(1);
        } catch (\Throwable $e) {
            self::fail('stop() must tolerate inner workers without a stop method: ' . $e->getMessage());
        }
    }

    private function saturatedMemoryPercent(int $memoryLimitBytes, int $fakeBytes): float
    {
        $GLOBALS['__zef_fake_memory'] = ['fake' => $fakeBytes];
        $app = $this->okApp();
        $request = $this->request('/sec-ok', 'GET');
        $captured = [];
        $worker = new RuntimeSecWorker([$request], onWait: static function () use ($app, &$captured): void {
            // Snapshot the log buffer at waitRequest() time — BEFORE the
            // respond path flushes and drains it — so the resource records
            // stay observable after the run.
            $telemetry = $app->getContainer()->get(Telemetry::class);
            if (!$telemetry instanceof Telemetry) {
                return;
            }
            $property = new \ReflectionProperty(Telemetry::class, 'logs');
            $raw = $property->getValue($telemetry);
            if (is_array($raw)) {
                $captured = $raw;
            }
        });
        $runtime = new RoadRunnerRuntime($app, $worker, 0, $memoryLimitBytes, false);

        $exit = $runtime->run();
        self::assertSame(0, $exit, 'saturation telemetry must not change the exit code');

        $events = array_values(array_filter(
            $captured,
            static fn (mixed $record): bool => $record instanceof LogRecord && $record->body === 'runtime.resource.saturated',
        ));
        self::assertNotSame([], $events, 'a saturated memory ratio must emit runtime.resource.saturated');
        self::assertGreaterThan(
            0,
            $this->meterCount($app, 'zef.runtime.resource.events.total', 'saturated'),
            'the saturated resource event must increment the runtime meter',
        );

        $attributes = $events[0]->attributes;
        self::assertIsFloat($attributes['memory.percent'] ?? null);

        return $attributes['memory.percent'];
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    private function loadShadows(): void
    {
        if (self::$shadowsLoaded) {
            return;
        }

        require_once __DIR__ . '/runtime-sec-shadow-functions.php';
        self::$shadowsLoaded = true;
    }

    private function request(string $path = '/', string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']));
    }

    private function withHeader(ServerRequest $request, string $name, string $value): ServerRequest
    {
        $result = $request->withHeader($name, $value);
        assert($result instanceof ServerRequest);

        return $result;
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'handler-reached');
            }
        };
    }

    private function authMiddleware(
        RuntimeSecRecordingAuthProvider $provider,
        RuntimeSecRecordingBoundary $boundary,
    ): AuthenticationMiddleware {
        return new AuthenticationMiddleware(
            $provider,
            new RuntimeSecNoopPolicy(),
            new RuntimeSecNoopReplayProtector(),
            $boundary,
        );
    }

    /** @return mixed decoded JSON body field of the response */
    private function jsonField(ResponseInterface $response, string $field): mixed
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey($field, $decoded);

        return $decoded[$field];
    }

    private function meterCount(Application $app, string $series, string $eventName): float|int
    {
        $telemetry = $app->getContainer()->get(Telemetry::class);
        self::assertInstanceOf(Telemetry::class, $telemetry);
        $snapshot = $telemetry->meter()->snapshot();
        $key = $series . '|' . json_encode(['event.name' => $eventName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $snapshot[$key]['count'] ?? 0;
    }

    private function okApp(): Application
    {
        $app = new Application();
        $app->addProvider(new readonly class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'sec-ok';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return [
                    'services' => [
                        'sec-ok.handler' => [
                            'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                #[\Override]
                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    return new Response(200, [], 'ok');
                                }
                            },
                            'deps' => [],
                        ],
                    ],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/sec-ok', 'handler' => 'sec-ok.handler'],
                    ],
                ];
            }
        });
        $app->boot();

        return $app;
    }
}

/**
 * @internal
 */
final class RuntimeSecRecordingAuthProvider implements CredentialProviderInterface
{
    /** @var list<int> */
    public array $timestamps = [];

    #[\Override]
    public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
    {
        $this->timestamps[] = $nowMs;

        return new AuthenticationResult(AuthenticationStatus::UNAUTHENTICATED);
    }
}

/**
 * @internal
 */
final class RuntimeSecRecordingBoundary implements SecurityBoundaryInterface
{
    /** @var list<int> */
    public array $timestamps = [];

    public ?SecurityRequest $lastRequest = null;

    #[\Override]
    public function admit(
        AuthenticationResult $authentication,
        SecurityRequest $request,
        AuthorizationPolicyInterface $authorization,
        ReplayProtectorInterface $replayProtector,
        int $nowMs,
    ): SecurityAdmissionDecision {
        $this->timestamps[] = $nowMs;
        $this->lastRequest = $request;

        return new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false);
    }
}

/**
 * @internal
 */
final class RuntimeSecNoopPolicy implements AuthorizationPolicyInterface
{
    #[\Override]
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
    {
        throw new \LogicException('not exercised through the fake boundary');
    }
}

/**
 * @internal
 */
final class RuntimeSecNoopReplayProtector implements ReplayProtectorInterface
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
final class RuntimeSecRecordingLimiter implements RateLimiterInterface
{
    /** @var list<string> */
    public array $keys = [];

    public function __construct(private readonly bool $allowed = true) {}

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        $this->keys[] = $key;

        return new RateLimitDecision($this->allowed, $limit, $this->allowed ? max(0, $limit - 1) : 0, 30);
    }
}

/**
 * @internal
 */
final class RuntimeSecThrowingLimiter implements RateLimiterInterface
{
    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        throw new \RuntimeException('limiter store unreachable');
    }
}

/**
 * @internal
 */
final class RuntimeSecWorker implements WorkerInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    /** @var list<string> */
    public array $errors = [];

    public int $stopCalls = 0;

    public int $waitCalls = 0;

    private int $nullPolls = 0;

    private bool $running = true;

    /** @param list<ServerRequestInterface> $requests */
    public function __construct(
        private array $requests,
        private readonly ?\Closure $onWait = null,
        private readonly ?string $waitFails = null,
    ) {}

    #[\Override]
    public function waitRequest(): ?ServerRequestInterface
    {
        ++$this->waitCalls;
        if ($this->onWait instanceof \Closure) {
            ($this->onWait)();
        }
        if ($this->waitFails !== null) {
            throw new \RuntimeException($this->waitFails);
        }
        if ($this->requests === []) {
            // Fail fast instead of polling null forever: the drain loop
            // must TERMINATE on its terminating null (mutation coverage
            // depends on a verdict, not a 90-second hang).
            if (++$this->nullPolls > 3) {
                throw new \RuntimeException('drain did not terminate after the null poll');
            }

            return null;
        }

        return array_shift($this->requests);
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    #[\Override]
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    #[\Override]
    public function stop(): void
    {
        ++$this->stopCalls;
        $this->running = false;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }
}
