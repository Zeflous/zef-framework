<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regresi I-6 (issue #174): middleware priority is now
 * honoured by PipelineFactory.
 *
 * The middleware.stack keys priority/group/tags were parsed but never
 * used — config order silently won, so a 'priority' hint (as printed by
 * make:middleware) did nothing. These tests pin the ordering contract:
 * highest priority first (the Router route / EventDispatcher listener
 * convention), ties keep the config declaration order (stable via an
 * explicit index tiebreak), and entries without a priority key default
 * to 0 — so priority-less stacks keep their exact config order.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\PipelineFactory;

/**
 * @internal
 */
final class PipelinePriorityOrderTest extends TestCase
{
    /**
     * Regresi I-6 (issue #174): out-of-order priorities plus one
     * default-priority entry — execution order must follow priority,
     * highest first, with the priority-less entry last (default 0).
     */
    public function testStackRunsHighestPriorityFirstWithDefaultLast(): void
    {
        $recorder = new PipelineOrderRecorder();
        $pipeline = $this->buildPipeline([
            ['service' => 'mw.a', 'priority' => 100],
            'mw.b',
            ['service' => 'mw.c', 'priority' => 900],
            ['service' => 'mw.d', 'priority' => 500],
        ], $recorder);

        $pipeline->handle($this->request());

        self::assertSame(['c', 'd', 'a', 'b'], $recorder->entries, 'priority 900 > 500 > 100 > default 0 — not config order');
    }

    /**
     * Regresi I-6 (issue #174): equal priorities keep the config
     * declaration order — the sort is stable by construction (index
     * tiebreak), never by engine usort stability.
     */
    public function testEqualPrioritiesKeepDeclarationOrder(): void
    {
        $recorder = new PipelineOrderRecorder();
        $pipeline = $this->buildPipeline([
            ['service' => 'mw.a', 'priority' => 5],
            ['service' => 'mw.b', 'priority' => 10],
            ['service' => 'mw.c', 'priority' => 10],
            ['service' => 'mw.d', 'priority' => 5],
        ], $recorder);

        $pipeline->handle($this->request());

        self::assertSame(['b', 'c', 'a', 'd'], $recorder->entries, 'within one priority band the declaration order wins');
    }

    /**
     * Regresi I-6 (issue #174): an omitted priority key ties with an
     * explicit priority 0 — every legacy stack (plain service-ID strings)
     * keeps its exact config order, the pre-I-6 behaviour.
     */
    public function testOmittedPriorityTiesWithExplicitZeroKeepingConfigOrder(): void
    {
        $recorder = new PipelineOrderRecorder();
        $pipeline = $this->buildPipeline([
            'mw.x',
            ['service' => 'mw.y', 'priority' => 0],
            'mw.z',
        ], $recorder);

        $pipeline->handle($this->request());

        self::assertSame(['x', 'y', 'z'], $recorder->entries, 'default 0 and explicit 0 are the same band — declaration order');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<mixed> $stack
     */
    private function buildPipeline(array $stack, PipelineOrderRecorder $recorder): MiddlewarePipeline
    {
        $container = new Container();
        foreach (['a', 'b', 'c', 'd', 'x', 'y', 'z'] as $label) {
            $container->registerDefinition(new ServiceDefinition(
                'mw.' . $label,
                static fn (): MiddlewareInterface => new RecordingMiddleware($label, $recorder),
            ));
        }
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

        return new PipelineFactory($container, $aggregator, $this->terminal())->build();
    }

    private function terminal(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'ok');
            }
        };
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost/', ['localhost']), [], [], [], [], null, []);
    }
}

/**
 * Shared execution-order recorder for the middleware doubles.
 *
 * @internal
 */
final class PipelineOrderRecorder
{
    /** @var list<string> */
    public array $entries = [];
}

/**
 * PSR-15 double that records its label into the shared recorder and
 * passes the request through unchanged.
 *
 * @internal
 */
final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $label,
        private readonly PipelineOrderRecorder $recorder,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->recorder->entries[] = $this->label;

        return $handler->handle($request);
    }
}
