<?php

declare(strict_types=1);

/*
 * ZEF Framework — regression suite for ZEF-DEEP-12 (issue #166).
 *
 * A deferred provider that never ran before validateAndFreeze() leaves
 * has() === false, yet get() used to throw a LogicException — breaking
 * the PSR-11 contract that container-agnostic callers rely on (they
 * catch NotFoundExceptionInterface). The frozen path now throws
 * ServiceNotFoundException with the actionable deferred-provider hint.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\DeferrableProviderInterface;
use Zef\Framework\Container\ServiceRegistrarInterface;
use Zef\Framework\Exception\ServiceNotFoundException;

/**
 * @internal
 */
final class ContainerPsr11DeferredFrozenTest extends TestCase
{
    /** The audit repro: frozen + deferred-pending -> has() false, get() PSR-11 NotFound. */
    public function testFrozenDeferredGetThrowsPsr11NotFound(): void
    {
        $container = new Container();
        $container->registerProvider(new class implements DeferrableProviderInterface {
            #[\Override]
            public function provides(): array
            {
                return ['late.service'];
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                $container->register('late.service', fn (): \stdClass => new \stdClass());
            }
        });
        $container->register('always.there', fn (): \stdClass => new \stdClass());
        $container->validateAndFreeze();

        self::assertFalse($container->has('late.service'), 'the deferred provider never ran, so the service is absent');

        try {
            $container->get('late.service');
            self::fail('Expected a PSR-11 NotFoundException for a frozen deferred service.');
        } catch (ServiceNotFoundException $e) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $e, 'PSR-11 catchability is the contract');
            self::assertSame('late.service', $e->serviceId);
            self::assertStringContainsString('already frozen', $e->getMessage(), 'the actionable hint survives');
            self::assertStringContainsString('register the provider as eager', $e->getMessage());
        }
    }

    /** Container-agnostic PSR-11 code catches the standard interface on this path. */
    public function testPsr11CatchBlockHandlesTheFrozenDeferredPath(): void
    {
        $container = new Container();
        $container->registerProvider(new class implements DeferrableProviderInterface {
            #[\Override]
            public function provides(): array
            {
                return ['late.service'];
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                $container->register('late.service', fn (): \stdClass => new \stdClass());
            }
        });
        $container->validateAndFreeze();

        $caught = null;

        try {
            $container->get('late.service');
        } catch (NotFoundExceptionInterface $e) {
            $caught = $e;
        }
        self::assertInstanceOf(NotFoundExceptionInterface::class, $caught, 'the standard PSR-11 catch must see it');
    }

    /** The non-frozen path still triggers the provider and resolves normally. */
    public function testDeferredProviderStillRunsBeforeFreeze(): void
    {
        $container = new Container();
        $container->registerProvider(new class implements DeferrableProviderInterface {
            #[\Override]
            public function provides(): array
            {
                return ['late.service'];
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                $container->register('late.service', fn (): \stdClass => new \stdClass());
            }
        });

        self::assertFalse($container->has('late.service'));
        self::assertInstanceOf(\stdClass::class, $container->get('late.service'), 'the deferred provider registers on first get()');
        self::assertTrue($container->has('late.service'));
    }

    /** The hint parameter is additive: the base message format is unchanged. */
    public function testServiceNotFoundExceptionMessageFormatIsBackwardCompatible(): void
    {
        self::assertSame(
            "Service 'plain.id' not found.",
            new ServiceNotFoundException('plain.id')->getMessage(),
        );
        self::assertSame(
            "Service 'mod.id' not found (module: 'Mod').",
            new ServiceNotFoundException('mod.id', 'Mod')->getMessage(),
        );
        self::assertSame(
            "Service 'hinted.id' not found. Do this instead.",
            new ServiceNotFoundException('hinted.id', null, 'Do this instead.')->getMessage(),
        );
    }
}
