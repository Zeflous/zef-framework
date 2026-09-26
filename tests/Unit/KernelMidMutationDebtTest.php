<?php

declare(strict_types=1);

/*
 * ZEF Framework — issue #90 round 4 (zone `ad-kernel-mid`) kill-map.
 *
 * Re-measured at 40bac12 (three file-group passes, summed): 171 mutants,
 * 120 killed, MSI 70.18. The gap concentrates in ResponseEmitter (75
 * mutants, 50.67% MSI — the SAPI surface is invisible to plain PHPUnit:
 * headers_list() is always empty in the CLI SAPI) plus the rethrow/code-0
 * seams of MiddlewareDefinition, ModuleBootstrapper, PipelineFactory and
 * the uncovered MiddlewarePipeline terminal-missing fallback.
 *
 * The ResponseEmitter assertions ride on the namespace shadows defined in
 * tests/Unit/kernel-mid-shadow-functions.php (Zef\Framework\header() and
 * Zef\Framework\http_response_code()), armed via $GLOBALS by these tests
 * only — see that file for the resolution/ordering rationale.
 *
 * Every test docblock names the exact mutants it kills; survivors are
 * documented as equivalents in the zone row, never forced.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Http\Response;
use Zef\Framework\MiddlewareDefinition;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\ModuleBootstrapper;
use Zef\Framework\PipelineFactory;
use Zef\Framework\ResponseEmitter;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class KernelMidMutationDebtTest extends TestCase
{
    // ==================================================================
    // Doubles
    // ==================================================================

    /** @var list<array{header: string, replace: bool}> */
    private array $headerCalls = [];

    /** @var list<null|int> */
    private array $statusCalls = [];
    // ------------------------------------------------------------------
    // ResponseEmitter — status wiring
    // ------------------------------------------------------------------

    /**
     * Kills Dispatcher neighbour ResponseEmitter:28 LogicalAnd family — the
     * 100..599 re-validation must wire ONLY in-range statuses through to
     * http_response_code(); foreign PSR-7 responses skip Zef's constructor
     * validation, so 99/600 must be silently ignored, 100/599 wired.
     */
    public function testEmitterWiresOnlyInRangeStatuses(): void
    {
        $this->armShadows();

        try {
            foreach ([200 => true, 100 => true, 599 => true, 99 => false, 600 => false, 999 => false] as $status => $mustWire) {
                $this->statusCalls = [];
                $this->emitForeign($status, [], new ScriptedStream(''));

                $wired = in_array($status, $this->statusCalls, true);
                self::assertSame(
                    $mustWire,
                    $wired,
                    "status {$status} wiring mismatch — recorded calls: " . json_encode($this->statusCalls),
                );
            }
        } finally {
            $this->disarmShadows();
        }
    }

    // ------------------------------------------------------------------
    // ResponseEmitter — Content-Length reconciliation
    // ------------------------------------------------------------------

    /**
     * Kills :37 NotIdentical (declared !== '' -> ===) and the :41 decision
     * family (LogicalNot/Identical/LogicalOr x2/CastInt/NotIdentical and the
     * AllSubExprNegation variants): an EXACT Content-Length survives, a lying
     * one is dropped, a non-digit one is dropped.
     */
    public function testEmitterReconcilesContentLengthAgainstTheStream(): void
    {
        $this->armShadows();

        try {
            // Exact: size 3, consumed 0, declared "3" -> header kept.
            $this->headerCalls = [];
            $this->emitForeign(200, ['Content-Length' => '3'], new ScriptedStream('abc'));
            self::assertSame(['Content-Length: 3'], $this->headerLines());
            self::assertSame([200], $this->statusCalls, 'The status wiring must run alongside an exact Content-Length');

            // Lying: size 3, consumed 0, declared "5" -> dropped.
            $this->headerCalls = [];
            $this->emitForeign(200, ['Content-Length' => '5'], new ScriptedStream('abc'));
            self::assertSame([], $this->headerLines(), 'A lying Content-Length must not be emitted');

            // Non-digit declared value -> dropped.
            $this->headerCalls = [];
            $this->emitForeign(200, ['Content-Length' => '12a'], new ScriptedStream('abcdefghijkl'));
            self::assertSame([], $this->headerLines(), 'A non-digit Content-Length must not be emitted');
        } finally {
            $this->disarmShadows();
        }
    }

    /**
     * Kills :39 Ternary (isSeekable() ? tell() : null — swapped) and the :40
     * arithmetic family (Minus, Ternary, NotIdentical on $size/$consumed,
     * LogicalAnd variants; IncrementInteger on the max(0,..) floor).
     *
     * The max(0 -> -1) DecrementInteger is a DOCUMENTED EQUIVALENT: a real
     * stream never has consumed > size, so max(-1, remaining) === max(0,
     * remaining) for every reachable input.
     */
    public function testEmitterContentLengthArithmeticUsesSizeMinusConsumed(): void
    {
        $this->armShadows();

        try {
            // Seekable stream positioned mid-way: size 10, tell 4 -> remaining 6.
            // Exact CL "6" kept (Minus->Plus gives 14; Ternary swap gives 10).
            $this->headerCalls = [];
            $mid = new ScriptedStream('0123456789');
            $mid->seek(4);
            $this->emitForeign(200, ['Content-Length' => '6'], $mid);
            self::assertSame(['Content-Length: 6'], $this->headerLines());

            // Same stream, CL matching the full size (10) != remaining (6) -> dropped.
            $this->headerCalls = [];
            $mid2 = new ScriptedStream('0123456789');
            $mid2->seek(4);
            $this->emitForeign(200, ['Content-Length' => '10'], $mid2);
            self::assertSame([], $this->headerLines());

            // Fully consumed stream: size 5, tell 5 -> remaining 0; CL "0" kept
            // (kills IncrementInteger max(0 -> 1): mutant computes 1 != 0).
            $this->headerCalls = [];
            $drained = new ScriptedStream('abcde');
            $drained->seek(5);
            $this->emitForeign(200, ['Content-Length' => '0'], $drained);
            self::assertSame(['Content-Length: 0'], $this->headerLines());

            // Non-seekable stream: tell() unavailable -> remaining = size.
            // CL "5" over a 5-octet non-seekable body is exact and kept
            // (kills the :39 Ternary swap: tell() throws on non-seekable).
            $this->headerCalls = [];
            $unseekable = new ScriptedStream('abcde', seekable: false);
            $this->emitForeign(200, ['Content-Length' => '5'], $unseekable);
            self::assertSame(['Content-Length: 5'], $this->headerLines());

            // Unknown size (getSize() === null) with a declared CL -> remaining
            // null -> dropped (kills NotIdentical null guards and LogicalAnd
            // variants: && -> || drives a null into the subtraction).
            $this->headerCalls = [];
            $unknown = new ScriptedStream('abcde', sizeUnknown: true);
            $this->emitForeign(200, ['Content-Length' => '5'], $unknown);
            self::assertSame([], $this->headerLines(), 'Unknown stream size must force the CL drop');

            // Unknown size with declared CL "0": the surviving && -> || mutant
            // drives null into the subtraction, floors it with max(0, ..) == 0
            // and keeps a header the original must still drop.
            $this->headerCalls = [];
            $unknownZero = new ScriptedStream('abcde', sizeUnknown: true);
            $this->emitForeign(200, ['Content-Length' => '0'], $unknownZero);
            self::assertSame([], $this->headerLines(), 'Unknown stream size must drop the CL even when it declares "0"');
        } finally {
            $this->disarmShadows();
        }
    }

    // ------------------------------------------------------------------
    // ResponseEmitter — header emission
    // ------------------------------------------------------------------

    /**
     * Kills :45 Foreach_, :46 ArrayItemRemoval + Foreach_ (both the non-array
     * wrap and the multi-value iteration) and the whole :53 Concat family
     * (Concat x2, ConcatOperandRemoval x3, FalseValue, FunctionCallRemoval):
     * exact "Name: value" lines, emitted in order, every one with
     * replace=false so repeated headers survive.
     */
    public function testEmitterEmitsExactHeaderLinesWithReplaceFalse(): void
    {
        $this->armShadows();

        try {
            $this->headerCalls = [];
            $this->emitForeign(200, [
                'Content-Type' => 'text/plain',
                'X-Multi' => ['a', 'b'],
                'X-Single' => 'one',
            ], new ScriptedStream(''));

            self::assertSame(
                ['Content-Type: text/plain', 'X-Multi: a', 'X-Multi: b', 'X-Single: one'],
                $this->headerLines(),
                'Headers must be emitted as exact "Name: value" lines in insertion order',
            );
            foreach ($this->headerCalls as $call) {
                self::assertFalse($call['replace'], 'Repeating headers must be appended (replace=false), never replaced');
            }
        } finally {
            $this->disarmShadows();
        }
    }

    /**
     * Kills :48/:49 MethodCallRemoval (assertName/assertValue skipped) and
     * covers the :51 Continue_ (skip-and-continue): an invalid header name
     * or value is skipped while the surrounding valid headers still ship.
     */
    public function testEmitterSkipsInvalidHeadersButContinues(): void
    {
        $this->armShadows();

        try {
            $this->headerCalls = [];
            $this->emitForeign(200, [
                'X-Good' => 'fine',
                'Bad Name' => 'x',
                'X-Control' => "bad\x01value",
                'X-Multi' => ["bad\x02first", 'good-second'],
                'X-Also-Good' => 'fine-too',
            ], new ScriptedStream(''));

            self::assertSame(
                ['X-Good: fine', 'X-Multi: good-second', 'X-Also-Good: fine-too'],
                $this->headerLines(),
                'An invalid name or an invalid FIRST value of a multi-value header must be skipped while the remaining values and headers still ship',
            );
        } finally {
            $this->disarmShadows();
        }
    }

    // ------------------------------------------------------------------
    // ResponseEmitter — body streaming and lifecycle
    // ------------------------------------------------------------------

    /**
     * Kills :72 IncrementInteger/DecrementInteger on the 8192 chunk size:
     * the stream double records every read() length it was asked for.
     */
    public function testEmitterReadsTheBodyInExact8192Chunks(): void
    {
        $stream = new ScriptedStream(str_repeat('x', 20000));
        \ob_start();

        try {
            new ResponseEmitter()->emit($this->foreign(200, [], $stream));
        } finally {
            \ob_end_clean();
        }
        self::assertSame([8192, 8192, 8192], $stream->readLengths, 'Every read must request exactly 8192 octets (the final chunk returns only the remaining bytes)');
    }

    /**
     * Kills :74 Break_ (empty-chunk guard): a stalled stream returning an
     * empty read while NOT at eof must terminate emission at that point.
     * The script terminates by itself, so the mutant fails fast instead of
     * timing out.
     */
    public function testEmitterStopsAtAnEmptyReadFromAStalledStream(): void
    {
        $stalled = new ScriptedStream('', readScript: ['A', '', 'B']);
        \ob_start();

        try {
            new ResponseEmitter()->emit($this->foreign(200, [], $stalled));
            $out = (string) \ob_get_contents();
        } finally {
            \ob_end_clean();
        }
        self::assertSame('A', $out, 'An empty read from a stalled (not-eof) stream must stop emission');
    }

    /**
     * Kills :59 UnwrapFinally on both paths: closeBody must close the stream
     * after a successful emission AND while the not-readable exception is
     * propagating.
     */
    public function testEmitterClosesTheBodyOnSuccessAndFailureWhenAsked(): void
    {
        $ok = new ScriptedStream('hi');
        \ob_start();

        try {
            new ResponseEmitter()->emit($this->foreign(200, [], $ok), closeBody: true);
        } finally {
            \ob_end_clean();
        }
        self::assertTrue($ok->closed, 'closeBody must close the stream after a successful emission');

        $unreadable = new ScriptedStream('x', readable: false);
        $threw = null;
        \ob_start();

        try {
            try {
                new ResponseEmitter()->emit($this->foreign(200, [], $unreadable), closeBody: true);
            } catch (\RuntimeException $e) {
                $threw = $e;
            }
        } finally {
            \ob_end_clean();
        }
        self::assertNotNull($threw, 'An unreadable body on a 200 response must throw');
        self::assertTrue($unreadable->closed, 'closeBody must close the stream even when emission throws');
    }

    /**
     * Kills MiddlewarePipeline:48 DecrementInteger/IncrementInteger on the
     * index advance (the no-advance variant otherwise recurses into a fatal,
     * which Infection records as an error rather than a kill): a middleware
     * must observe the NEXT handler exactly one level deeper — a second
     * invocation of the same middleware means the index never advanced.
     */
    public function testPipelineAdvancesTheStackIndexExactlyOneStepPerLevel(): void
    {
        $request = self::createStub(ServerRequestInterface::class);
        $middleware = new class implements MiddlewareInterface {
            public int $calls = 0;

            #[\Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                ++$this->calls;
                if ($this->calls > 1) {
                    throw new \RuntimeException('The same middleware ran twice — the pipeline index did not advance.');
                }

                return $handler->handle($request);
            }
        };
        $terminal = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(418, [], 'terminal');
            }
        };

        $pipeline = new MiddlewarePipeline([$middleware], $terminal);
        \ob_start();

        try {
            $response = $pipeline->handle($request);
        } finally {
            \ob_end_clean();
        }
        self::assertSame(418, $response->getStatusCode());
        self::assertSame(1, $middleware->calls, 'A single middleware must be visited exactly once per request');
    }

    // ------------------------------------------------------------------
    // MiddlewareDefinition — fromArray() rewrap seam
    // ------------------------------------------------------------------

    /**
     * Kills MiddlewareDefinition:63 DecrementInteger/IncrementInteger (the
     * rewrap code 0): constructor rejections surfaced through fromArray must
     * carry code exactly 0 with the original exception chained as previous.
     */
    public function testFromArrayRewrapsConstructorRejectionsWithCodeZero(): void
    {
        foreach (
            [
                ['service' => 'svc.a', 'group' => ''],
                ['service' => 'svc.b', 'tags' => [123]],
                ['service' => 'svc.c', 'tags' => ['']],
            ] as $config
        ) {
            try {
                MiddlewareDefinition::fromArray($config);
                self::fail('Expected InvalidConfigurationException for ' . json_encode($config));
            } catch (InvalidConfigurationException $e) {
                self::assertSame(0, $e->getCode(), 'The fromArray rewrap must carry exception code exactly 0');
                self::assertNotNull($e->getPrevious(), 'The original InvalidArgumentException must stay chained');
                self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            }
        }
    }

    // ------------------------------------------------------------------
    // MiddlewarePipeline — terminal-missing fallback
    // ------------------------------------------------------------------

    /**
     * Kills MiddlewarePipeline:43 DecrementInteger/IncrementInteger (500 ->
     * 499/501), ArrayItemRemoval (Content-Type header) and ReturnRemoval:
     * an exhausted stack without a terminal answers the exact canned 500.
     */
    public function testPipelineWithoutTerminalReturnsTheExactMissingResponse(): void
    {
        $request = self::createStub(ServerRequestInterface::class);
        $response = new MiddlewarePipeline([])->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('Pipeline terminal missing.', (string) $response->getBody());

        $withTerminal = new MiddlewarePipeline([], new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(418, [], 'terminal');
            }
        })->handle($request);
        self::assertSame(418, $withTerminal->getStatusCode(), 'A real terminal must win over the fallback');
    }

    // ------------------------------------------------------------------
    // ModuleBootstrapper — registerModule() rewrap seam
    // ------------------------------------------------------------------

    /**
     * Kills ModuleBootstrapper:34 DecrementInteger/IncrementInteger (the
     * rewrap code 0): an invalid module definition is re-thrown with code
     * exactly 0, a stable message prefix and the cause chained.
     */
    public function testRegisterModuleRewrapsInvalidDefinitionsWithCodeZero(): void
    {
        $bootstrapper = new ModuleBootstrapper(new Container(), $this->routerStub());

        try {
            $bootstrapper->registerModule('m', ['services' => 'not-an-array']);
            self::fail('Expected InvalidConfigurationException for an invalid module definition');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(0, $e->getCode(), 'The registerModule rewrap must carry exception code exactly 0');
            self::assertStringStartsWith("Module 'm' has invalid definition:", $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'The original cause must stay chained');
        }
    }

    // ------------------------------------------------------------------
    // PipelineFactory — build() rejection seams
    // ------------------------------------------------------------------

    /**
     * Kills PipelineFactory:39 DecrementInteger/IncrementInteger and Throw_
     * (the fromLegacy rewrap): an invalid middleware entry aborts the build
     * with code 0 and the cause chained; the throw must not be swallowed.
     */
    public function testFactoryRejectsInvalidMiddlewareEntriesWithCodeZero(): void
    {
        $factory = new PipelineFactory(new Container(), $this->configWithStack(['']), $this->handlerStub());

        try {
            $factory->build();
            self::fail('Expected InvalidConfigurationException for an invalid middleware entry');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(0, $e->getCode(), 'The fromLegacy rewrap must carry exception code exactly 0');
            self::assertNotNull($e->getPrevious(), 'The original cause must stay chained');
        }
    }

    /**
     * Kills PipelineFactory:45 Throw_ (unregistered middleware service): the
     * build must abort with the exact message instead of continuing.
     */
    public function testFactoryRejectsUnregisteredMiddlewareServices(): void
    {
        $factory = new PipelineFactory(new Container(), $this->configWithStack([['service' => 'ghost.mw']]), $this->handlerStub());

        try {
            $factory->build();
            self::fail('Expected InvalidConfigurationException for an unregistered middleware service');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Middleware service 'ghost.mw' is not registered.", $e->getMessage());
        }
    }

    /**
     * Kills PipelineFactory:49 Throw_ (registered but not a middleware): a
     * service that resolves to a non-middleware object must abort the build
     * with the exact message.
     */
    public function testFactoryRejectsNonMiddlewareServices(): void
    {
        $container = new Container();
        $container->registerDefinition(new ServiceDefinition('wrong.svc', static fn (): \stdClass => new \stdClass()));
        $factory = new PipelineFactory($container, $this->configWithStack([['service' => 'wrong.svc']]), $this->handlerStub());

        try {
            $factory->build();
            self::fail('Expected InvalidConfigurationException for a non-middleware service');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Service 'wrong.svc' does not implement MiddlewareInterface.", $e->getMessage());
        }
    }

    private function armShadows(): void
    {
        $GLOBALS['__zef_header_calls'] = [];
        $GLOBALS['__zef_status_calls'] = [];
        $this->headerCalls = &$GLOBALS['__zef_header_calls'];
        $this->statusCalls = &$GLOBALS['__zef_status_calls'];
    }

    private function disarmShadows(): void
    {
        unset($GLOBALS['__zef_header_calls'], $GLOBALS['__zef_status_calls']);
    }

    /** @return list<string> */
    private function headerLines(): array
    {
        $lines = [];
        foreach ($this->headerCalls as $call) {
            $lines[] = $call['header'];
        }

        return $lines;
    }

    /**
     * @param array<string, list<string>|string> $headers
     */
    private function foreign(int $status, array $headers, StreamInterface $body): ResponseInterface
    {
        return new ForeignResponse($status, $headers, $body);
    }

    /**
     * @param array<string, list<string>|string> $headers
     */
    private function emitForeign(int $status, array $headers, StreamInterface $body): void
    {
        \ob_start();

        try {
            new ResponseEmitter()->emit($this->foreign($status, $headers, $body));
        } finally {
            \ob_end_clean();
        }
    }

    private function routerStub(): Router
    {
        return new Router();
    }

    private function handlerStub(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '');
            }
        };
    }

    /** @param list<mixed> $stack */
    private function configWithStack(array $stack): ConfigAggregator
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider(new readonly class($stack) implements ConfigProviderInterface {
            /** @param list<mixed> $stack */
            public function __construct(private array $stack) {}

            #[\Override]
            public function getModuleName(): string
            {
                return 'middleware';
            }

            /** @return array<string, mixed> */
            #[\Override]
            public function getConfig(): array
            {
                return ['stack' => $this->stack];
            }
        });
        $aggregator->merge();

        return $aggregator;
    }
}

/**
 * Stream double with scriptable read behaviour for ResponseEmitter kill tests.
 *
 * - readLengths records every read() length (kills the 8192 chunk-size
 *   mutants);
 * - readScript, when set, replaces substr-based reads entirely: each call
 *   returns the next scripted string and eof() only turns true once the
 *   script is exhausted (a '' script entry = stalled-but-not-eof read, the
 *   input the :74 Break_ guard exists for);
 * - sizeUnknown makes getSize() return null (the remaining === null branch);
 * - seekable/readable toggle the capability probes; tell() is honest.
 */
final class ScriptedStream implements StreamInterface
{
    public bool $closed = false;

    /** @var list<int> */
    public array $readLengths = [];

    private int $pos = 0;

    private int $scriptIndex = 0;

    /**
     * @param null|list<string> $readScript
     */
    public function __construct(
        private readonly string $data = '',
        private readonly bool $readable = true,
        private readonly bool $seekable = true,
        private readonly bool $sizeUnknown = false,
        private readonly ?array $readScript = null,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->data;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function detach(): mixed
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->sizeUnknown ? null : strlen($this->data);
    }

    #[\Override]
    public function tell(): int
    {
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable.');
        }

        return $this->pos;
    }

    #[\Override]
    public function eof(): bool
    {
        if ($this->readScript !== null) {
            return $this->scriptIndex >= count($this->readScript);
        }

        return $this->pos >= strlen($this->data);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    #[\Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('Stream is not seekable.');
        }
        $this->pos = $whence === \SEEK_SET ? $offset : $this->pos + $offset;
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Stream is not writable.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->readable;
    }

    #[\Override]
    public function read(int $length): string
    {
        if ($this->readScript !== null) {
            $value = $this->readScript[$this->scriptIndex] ?? '';
            ++$this->scriptIndex;

            return $value;
        }

        $this->readLengths[] = $length;
        $chunk = substr($this->data, $this->pos, $length);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        return substr($this->data, $this->pos);
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}

/**
 * Foreign (non-Zef) PSR-7 response double: skips Zef's constructor-time
 * status validation exactly like the real foreign implementations the
 * ResponseEmitter:28 guard exists for, and withoutHeader() actually
 * removes the header (the emitter relies on it for the CL drop).
 */
final class ForeignResponse implements ResponseInterface
{
    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly StreamInterface $body,
    ) {}

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->status;
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return new self($code, $this->headers, $this->body);
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return '';
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    #[\Override]
    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getHeaders(): array
    {
        return $this->headers;
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return array_any($this->headers, fn ($_value, $key): bool => strcasecmp($key, $name) === 0);
    }

    /** @return list<string> */
    #[\Override]
    public function getHeader(string $name): array
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                $values = is_array($value) ? $value : [$value];

                return array_values(array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    $values,
                ));
            }
        }

        return [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /** @param mixed $value */
    #[\Override]
    public function withHeader(string $name, $value): ResponseInterface
    {
        return new self($this->status, [$name => $value] + $this->headers, $this->body);
    }

    /** @param mixed $value */
    #[\Override]
    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        return new self($this->status, [$name => $value] + $this->headers, $this->body);
    }

    #[\Override]
    public function withoutHeader(string $name): ResponseInterface
    {
        $headers = $this->headers;
        foreach ($headers as $key => $_value) {
            if (strcasecmp($key, $name) === 0) {
                unset($headers[$key]);
            }
        }

        return new self($this->status, $headers, $this->body);
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[\Override]
    public function withBody(StreamInterface $body): ResponseInterface
    {
        return new self($this->status, $this->headers, $body);
    }
}
