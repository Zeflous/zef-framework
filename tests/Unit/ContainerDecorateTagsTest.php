<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-08 (issue #162):
 * decorating a tagged service must not strip its tags.
 *
 * Pre-fix, Container::decorate() re-homed the original definition under the
 * synthetic "@inner:<id>:base" id WITH its tags, while the wrapper that took
 * over the original id carried none — so TaggedServiceLocator indexed the
 * synthetic id and resolveAll() silently returned the UNDECORATED inner
 * instance (and resolveOne() the same), with no error anywhere.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\TaggedServiceLocator;

/**
 * @internal
 */
final class ContainerDecorateTagsTest extends TestCase
{
    /** The audit PoC: a decorated tagged service must resolve DECORATED through the tag. */
    public function testDecoratedServiceKeepsItsTagAndResolvesDecorated(): void
    {
        $c = new Container();
        $this->registerJsonExporter($c);
        $this->registerCsvExporter($c);
        $c->decorate('exp.json', static fn (ContainerInterface $ctx, string $inner): string => "traced({$inner})");
        $c->validateAndFreeze();

        $locator = new TaggedServiceLocator($c, $c->getRegistry());

        self::assertSame(
            ['exp.json', 'exp.csv'],
            $locator->idsFor('acme.exporter'),
            'the tag must keep pointing at the ORIGINAL id — never at a synthetic @inner id',
        );
        self::assertSame(
            ['traced(json)', 'csv'],
            $locator->resolveAll('acme.exporter'),
            'the decorated service must arrive decorated; the undecorated sibling must stay untouched',
        );
    }

    /** resolveOne() must return the decorated instance (pre-fix: the bare inner). */
    public function testResolveOneReturnsTheDecoratedInstance(): void
    {
        $c = new Container();
        $this->registerJsonExporter($c);
        $c->decorate('exp.json', static fn (ContainerInterface $ctx, string $inner): string => "traced({$inner})");
        $c->validateAndFreeze();

        $locator = new TaggedServiceLocator($c, $c->getRegistry());

        self::assertSame('traced(json)', $locator->resolveOne('acme.exporter'));
        self::assertSame('traced(json)', $locator->requireOne('acme.exporter'));
    }

    /** Multi-decorator chains: exactly ONE tag entry, resolving the fully wrapped instance. */
    public function testDecoratorChainsKeepASingleTagEntry(): void
    {
        $c = new Container();
        $this->registerJsonExporter($c);
        $c->decorate('exp.json', static fn (ContainerInterface $ctx, string $inner): string => "A({$inner})");
        $c->decorate('exp.json', static fn (ContainerInterface $ctx, string $inner): string => "B({$inner})");
        $c->validateAndFreeze();

        $locator = new TaggedServiceLocator($c, $c->getRegistry());

        self::assertSame(['exp.json'], $locator->idsFor('acme.exporter'), 'no synthetic inner id may leak into the tag index');
        self::assertSame(['A(B(json))'], $locator->resolveAll('acme.exporter'), 'first-registered decorator outermost, exactly one instance');
    }

    /** Decorating an untagged service must not suddenly make it taggable either. */
    public function testUntaggedServiceStaysUntaggedAfterDecoration(): void
    {
        $c = new Container();
        $c->registerDefinition(new ServiceDefinition(
            'exp.plain',
            static fn (): string => 'plain',
            [],
            null,
            ServiceLifetime::SINGLETON,
            true,
            false,
        ));
        $c->decorate('exp.plain', static fn (ContainerInterface $ctx, string $inner): string => "traced({$inner})");
        $c->validateAndFreeze();

        $locator = new TaggedServiceLocator($c, $c->getRegistry());

        self::assertSame([], $locator->idsFor('acme.exporter'));
        self::assertSame('traced(plain)', $c->get('exp.plain'));
    }

    private function registerJsonExporter(Container $c): void
    {
        $c->registerDefinition(new ServiceDefinition(
            'exp.json',
            static fn (): string => 'json',
            [],
            null,
            ServiceLifetime::SINGLETON,
            true,
            false,
            ['acme.exporter'],
        ));
    }

    private function registerCsvExporter(Container $c): void
    {
        $c->registerDefinition(new ServiceDefinition(
            'exp.csv',
            static fn (): string => 'csv',
            [],
            null,
            ServiceLifetime::SINGLETON,
            true,
            false,
            ['acme.exporter'],
        ));
    }
}
