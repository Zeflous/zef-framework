<?php

declare(strict_types=1);

/*
 * ZEF Framework — Mutation deep-dive #3 (v2.14.0): lifecycle, admission and
 * authentication behavior tests for the worst measured cluster (Adapters/
 * Runtime + Adapters/Security, covered MSI 42%). Observable exit codes,
 * counters, worker sinks and captured SecurityRequests are asserted so that
 * structural mutants (default parameters, flags, MethodCallRemoval) die.
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
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\TinkerSession;
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

/**
 * @internal
 */
final class MutationDeepRuntimeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_RUNTIME_CONTROL_PLANE');
        putenv('ZEF_RUNTIME_RESOURCE_CAPACITY');
        putenv('ZEF_RUNTIME_SATURATION_PERCENT');
    }

    // -----------------------------------------------------------------
    // RoadRunnerRuntime — construction and guards
    // -----------------------------------------------------------------

    public function testRuntimeConstructorValidatesLimitBoundaries(): void
    {
        $app = new Application();
        $worker = new MutationDeepRuntimeWorker([]);

        try {
            new RoadRunnerRuntime($app, $worker, -1, 0, false);
            self::fail('Negative maxJobs must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        try {
            new RoadRunnerRuntime($app, $worker, 0, -1, false);
            self::fail('Negative memoryLimitBytes must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        self::assertFalse($runtime->isRunning(), 'boundary values 0/0 construct fine');
    }

    public function testRuntimeRejectsInvalidControlPlaneValue(): void
    {
        putenv('ZEF_RUNTIME_CONTROL_PLANE=bogus');

        $this->expectException(\InvalidArgumentException::class);
        new RoadRunnerRuntime(new Application(), new MutationDeepRuntimeWorker([]), 0, 0, false);
    }

    public function testRuntimeAcceptsControlPlaneCaseAndSpaceVariants(): void
    {
        putenv('ZEF_RUNTIME_CONTROL_PLANE=ON');
        self::assertInstanceOf(
            RoadRunnerRuntime::class,
            new RoadRunnerRuntime(new Application(), new MutationDeepRuntimeWorker([]), 0, 0, false),
        );
        putenv('ZEF_RUNTIME_CONTROL_PLANE= off ');
        self::assertInstanceOf(
            RoadRunnerRuntime::class,
            new RoadRunnerRuntime(new Application(), new MutationDeepRuntimeWorker([]), 0, 0, false),
        );
    }

    public function testRuntimeIsSingleUse(): void
    {
        $runtime = new RoadRunnerRuntime(new Application(), new MutationDeepRuntimeWorker([]), 0, 0, false);
        self::assertSame(0, $runtime->run());

        try {
            $runtime->run();
            self::fail('Restart after shutdown must be rejected.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('single-use', $e->getMessage());
        }
    }

    public function testRuntimeRejectsReentrantRun(): void
    {
        $holder = new MutationDeepRuntimeHolder();
        $reentrant = new MutationDeepRuntimeWorker([], onWait: static function () use ($holder): void {
            $inner = $holder->runtime;
            if (!$inner instanceof RoadRunnerRuntime) {
                return;
            }

            try {
                $inner->run();
            } catch (\LogicException $e) {
                throw new MutationDeepRuntimeProbe('already-running: ' . $e->getMessage(), $e->getCode(), $e);
            }
        });
        $holder->runtime = new RoadRunnerRuntime(new Application(), $reentrant, 0, 0, false);

        self::assertSame(1, $holder->runtime->run(), 'the nested run() probe surfaces as a worker failure');
        self::assertStringContainsString(
            'already-running: Runtime is already running.',
            $reentrant->errors[0] ?? '',
        );
    }

    // -----------------------------------------------------------------
    // RoadRunnerRuntime — serving loop
    // -----------------------------------------------------------------

    public function testRuntimeServesUntilMaxJobsThenStops(): void
    {
        $app = $this->okApp();
        $worker = new MutationDeepRuntimeWorker([
            $this->request('/ok'),
            $this->request('/ok'),
            $this->request('/never'),
        ]);
        $runtime = new RoadRunnerRuntime($app, $worker, 2, 0, false);

        self::assertSame(0, $runtime->run());
        self::assertSame(2, $runtime->handledRequests(), 'maxJobs=2 stops after the second job');
        self::assertCount(2, $worker->responses);
        self::assertSame(1, $worker->stopCalls, 'stop() delegates to the worker once');
        self::assertFalse($runtime->isRunning());
    }

    public function testRuntimeDefaultMaxJobsIsUnlimited(): void
    {
        $app = $this->okApp();
        $worker = new MutationDeepRuntimeWorker([
            $this->request('/ok'),
            $this->request('/ok'),
        ]);
        $runtime = new RoadRunnerRuntime($app, $worker);

        self::assertSame(0, $runtime->run());
        self::assertSame(2, $runtime->handledRequests(), 'default maxJobs 0 serves every request');
        self::assertSame(0, $worker->stopCalls, 'no stop is triggered for unlimited jobs');
    }

    public function testRuntimeMemoryLimitExceededExitsWithTwo(): void
    {
        $app = $this->okApp();
        $worker = new MutationDeepRuntimeWorker([
            $this->request('/ok'),
            $this->request('/never'),
        ]);
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 1, false);

        self::assertSame(2, $runtime->run(), 'memory limit breach exits with code 2');
        self::assertSame(0, $runtime->handledRequests(), 'breach detected before serving');
        self::assertSame(1, $worker->stopCalls);
    }

    public function testRuntimeMemoryLimitDefaultIsDisabled(): void
    {
        $app = $this->okApp();
        $worker = new MutationDeepRuntimeWorker([$this->request('/ok')]);
        $runtime = new RoadRunnerRuntime($app, $worker);

        self::assertSame(0, $runtime->run(), 'default memoryLimitBytes 0 never triggers the memory exit');
    }

    public function testRuntimeWorkerPipeFailureReportsAndExitsOne(): void
    {
        $app = new Application();
        $worker = new MutationDeepRuntimeWorker([], waitFails: 'pipe broken');
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);

        self::assertSame(1, $runtime->run());
        self::assertSame(['RuntimeException: pipe broken'], $worker->errors);
        self::assertSame([], $worker->responses);
    }

    public function testRuntimeHandlerFailureResponds500AndExitsOne(): void
    {
        $app = $this->boomApp();
        $worker = new MutationDeepRuntimeWorker([$this->request('/deep-boom')]);
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);

        self::assertSame(1, $runtime->run());
        self::assertCount(1, $worker->responses);
        self::assertSame(500, $worker->responses[0]->getStatusCode());
        self::assertSame(1, $runtime->handledRequests());
    }

    public function testRuntimeEndsCleanlyWhenNoRequestArrives(): void
    {
        $app = $this->okApp();
        $worker = new MutationDeepRuntimeWorker([]);
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);

        self::assertSame(0, $runtime->run());
        self::assertSame(0, $runtime->handledRequests());
        self::assertSame([], $worker->responses);
        self::assertFalse($runtime->isRunning());
    }

    // -----------------------------------------------------------------
    // AuthenticationMiddleware
    // -----------------------------------------------------------------

    public function testAuthRejectsUnsafeAnonymousRequestsWith401(): void
    {
        $middleware = $this->authMiddleware(null, allow: true);
        $handler = new MutationDeepRecordingHandler();
        $response = $middleware->process($this->withHeader($this->request('/private', 'POST'), 'X-Probe', '1'), $handler);

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('Authentication required', $body['error'] ?? $body['message'] ?? null);
        self::assertArrayHasKey('correlation_id', $body);
        self::assertFalse($handler->seen, 'handler must not run for denied requests');
    }

    public function testAuthExtractsBearerCredentialsWithBounds(): void
    {
        $middleware = $this->authMiddleware(null, allow: true);

        foreach (['Bearer   ', 'Basic zzz', 'Bearer ' . str_repeat('t', CredentialHandle::MAX_ID_BYTES + 1)] as $badHeader) {
            $handler = new MutationDeepRecordingHandler();
            $response = $middleware->process($this->withHeader($this->request('/p', 'POST'), 'Authorization', $badHeader), $handler);
            self::assertSame(401, $response->getStatusCode(), "header '{$badHeader}' yields no usable credential");
        }

        $handler = new MutationDeepRecordingHandler();
        $ok = $middleware->process(
            $this->withHeader($this->request('/p', 'POST'), 'Authorization', 'Bearer ' . str_repeat('t', CredentialHandle::MAX_ID_BYTES)),
            $handler,
        );
        self::assertSame(200, $ok->getStatusCode(), 'token of exactly MAX_ID_BYTES is accepted');
    }

    public function testAuthPassesSafeAnonymousRequestsThrough(): void
    {
        $middleware = $this->authMiddleware(null, allow: true);
        $handler = new MutationDeepRecordingHandler();
        $response = $middleware->process($this->request('/open', 'GET'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($handler->seen);
        self::assertSame('anonymous', $handler->principal);
    }

    public function testAuthDeniesWith403ForAuthenticatedContexts(): void
    {
        $context = new SecurityContext('user-1', 'bearer', 'ctx', 'scope', null);
        $middleware = $this->authMiddleware($context, allow: false);
        $handler = new MutationDeepRecordingHandler();
        $request = $this->request('/p', 'POST');
        $response = $middleware->process($this->withHeader($request, 'Authorization', 'Bearer token-x'), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($handler->seen);
    }

    public function testAuthBoundsOperationAndReplayIdWithoutTruncatingResource(): void
    {
        $boundary = new MutationDeepAuthBoundary(allow: true);
        $middleware = new AuthenticationMiddleware(
            new MutationDeepAuthProvider(null),
            new MutationDeepNoopPolicy(),
            new MutationDeepNoopReplayProtector(),
            $boundary,
        );
        $handler = new MutationDeepRecordingHandler();

        $longPath = '/' . str_repeat('a', SecurityRequest::MAX_RESOURCE_BYTES - 1);
        $request = $this->withHeader(
            $this->withHeader($this->request($longPath, 'POST'), 'Authorization', 'Bearer token-x'),
            'X-Replay-Id',
            str_repeat('r', 150),
        );
        $middleware->process($request, $handler);

        $captured = $boundary->lastRequest;
        self::assertNotNull($captured);
        self::assertSame(SecurityRequest::MAX_OPERATION_BYTES, strlen($captured->operationClass));
        self::assertSame($longPath, $captured->resource);
        self::assertSame('POST', $captured->action);
        self::assertSame(SecurityRequest::MAX_REPLAY_ID_BYTES, strlen((string) $captured->replayId));
    }

    public function testAuthForwardsPrincipalAttributeOnSuccess(): void
    {
        $context = new SecurityContext('user-42', 'bearer', 'ctx', 'scope', null);
        $middleware = $this->authMiddleware($context, allow: true);
        $handler = new MutationDeepRecordingHandler();
        $request = $this->request('/p', 'POST');
        $response = $middleware->process($this->withHeader($request, 'Authorization', 'Bearer token-x'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user-42', $handler->principal);
    }

    // -----------------------------------------------------------------
    // TinkerSession
    // -----------------------------------------------------------------

    public function testTinkerSkipsBlankAndCommentLinesAndHonorsExit(): void
    {
        $session = new TinkerSession();
        $outputs = $session->run([
            '',
            '   ',
            '# a comment',
            '$a = 1;',
            'exit',
            '$b = 2;',
        ]);

        self::assertSame(['null', 'bye.'], $outputs);
        self::assertSame(['a' => 1], $session->snapshot());
    }

    public function testTinkerQuitsAlsoStopsExecution(): void
    {
        $session = new TinkerSession();
        $outputs = $session->run(['quit', '$c = 3;']);
        self::assertSame(['bye.'], $outputs);
        self::assertSame([], $session->snapshot());
    }

    public function testTinkerStatePersistsAcrossEvaluations(): void
    {
        $session = new TinkerSession();
        self::assertSame('null', $session->evaluate('$x = 41;'), 'assignment is a statement yielding null');
        self::assertSame('42', $session->evaluate('$x + 1'));
        $session->set('k', 21);
        self::assertSame('42', $session->evaluate('$k * 2'));
    }

    public function testTinkerRendersValuesAndErrors(): void
    {
        $session = new TinkerSession();
        self::assertSame('5', $session->evaluate('2 + 3'), 'expression without semicolon returns');
        self::assertSame('null', $session->evaluate('2 + 3;'), 'statement form yields null');
        $error = $session->evaluate('this_function_does_not_exist_xyz()');
        self::assertStringStartsWith('[error] Error:', $error);
    }

    public function testTinkerTruncatesOutputAtDefaultLimit(): void
    {
        $session = new TinkerSession();
        $exact = $session->evaluate("'" . str_repeat('a', 238) . "'");
        self::assertSame(240, strlen($exact), 'var_export of 238 chars (quoted = 240 bytes) is not truncated');

        $over = $session->evaluate("'" . str_repeat('a', 239) . "'");
        self::assertSame(243, strlen($over), 'truncated output is 240 chars plus the 3-byte ellipsis');
        self::assertStringEndsWith("\u{2026}", $over);
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

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

    private function okApp(): Application
    {
        return $this->routedApp('deep-ok', static fn (): Response => new Response(200, [], 'ok'));
    }

    private function boomApp(): Application
    {
        return $this->routedApp('deep-boom', static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('handler exploded');
            }
        });
    }

    private function routedApp(string $moduleName, callable $factory): Application
    {
        $app = new Application();
        $app->addProvider(new readonly class($moduleName, $factory(...)) implements ConfigProviderInterface {
            public function __construct(
                private string $name,
                private \Closure $factory,
            ) {}

            #[\Override]
            public function getModuleName(): string
            {
                return $this->name;
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return [
                    'services' => [
                        $this->name . '.handler' => [
                            'factory' => $this->factory,
                            'deps' => [],
                        ],
                    ],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/' . $this->name, 'handler' => $this->name . '.handler'],
                        ['method' => 'POST', 'path' => '/' . $this->name, 'handler' => $this->name . '.handler'],
                    ],
                ];
            }
        });
        $app->boot();

        return $app;
    }

    private function authMiddleware(?SecurityContext $context, bool $allow): AuthenticationMiddleware
    {
        return new AuthenticationMiddleware(
            new MutationDeepAuthProvider($context),
            new MutationDeepNoopPolicy(),
            new MutationDeepNoopReplayProtector(),
            new MutationDeepAuthBoundary($allow),
        );
    }
}

/**
 * @internal
 */
final class MutationDeepRuntimeProbe extends \RuntimeException {}

/**
 * @internal
 */
final class MutationDeepRuntimeWorker implements WorkerInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    /** @var list<string> */
    public array $errors = [];

    public int $stopCalls = 0;

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
        if ($this->onWait instanceof \Closure) {
            ($this->onWait)();
        }
        if ($this->waitFails !== null) {
            throw new \RuntimeException($this->waitFails);
        }
        if ($this->requests === []) {
            // Fail fast instead of polling null forever: the drain loop
            // must TERMINATE on its terminating null (a hung loop gives
            // mutation testing a timeout instead of a verdict).
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

/**
 * @internal
 */
final class MutationDeepRuntimeHolder
{
    public ?RoadRunnerRuntime $runtime = null;
}

/**
 * @internal
 */
final class MutationDeepRecordingHandler implements RequestHandlerInterface
{
    public bool $seen = false;

    public ?string $principal = null;

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen = true;
        $principal = $request->getAttribute('zef.security.principal');
        $this->principal = is_string($principal) ? $principal : null;

        return new Response(200, [], 'handler-reached');
    }
}

/**
 * @internal
 */
final class MutationDeepAuthProvider implements CredentialProviderInterface
{
    public function __construct(private readonly ?SecurityContext $context) {}

    #[\Override]
    public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
    {
        return new AuthenticationResult(
            $this->context instanceof SecurityContext ? AuthenticationStatus::AUTHENTICATED : AuthenticationStatus::UNAUTHENTICATED,
            $this->context,
        );
    }
}

/**
 * @internal
 */
final class MutationDeepAuthBoundary implements SecurityBoundaryInterface
{
    public ?SecurityRequest $lastRequest = null;

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

        return $this->allow
            ? new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false)
            : new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::AUTHORIZATION_DENIED, false);
    }
}

/**
 * @internal
 */
final class MutationDeepNoopPolicy implements AuthorizationPolicyInterface
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
final class MutationDeepNoopReplayProtector implements ReplayProtectorInterface
{
    #[\Override]
    public function check(?string $replayId, int $nowMs): ReplayResult
    {
        throw new \LogicException('not exercised through the fake boundary');
    }
}
