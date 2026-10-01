<?php

declare(strict_types=1);

/*
 * Audit #305 regression: an optional union/intersection parameter in a
 * NON-final constructor position must still occupy one entry in the
 * positional argument plan. assertSupportedType() used to append NOTHING
 * for optional unsupported types, so every later argument shifted left:
 * a TypeError at first resolution, or — worse — a silently wrong value
 * when the shifted types happened to be compatible.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Autowiring\AutowireCompilerPass;
use Zef\Framework\Container\Container;

/**
 * @internal
 */
final class Audit305AutowireUnionPositionalTest extends TestCase
{
    public function testOptionalUnionMidPositionKeepsLaterArgumentsInPlace(): void
    {
        $c = new Container();
        new AutowireCompilerPass()->process($c, [Audit305MidUnionUser::class]);
        $c->validateAndFreeze();

        $user = $c->get(Audit305MidUnionUser::class);

        self::assertInstanceOf(\stdClass::class, $user->probe, 'the class dependency lands in its own slot');
        self::assertSame('fallback', $user->mode, 'the optional union keeps its declared default');
        self::assertSame('anon', $user->label, 'the argument AFTER the union is not shifted into it');
    }

    public function testSilentlyCompatibleShiftIsAlsoPrevented(): void
    {
        $c = new Container();
        new AutowireCompilerPass()->process($c, [Audit305StringShiftUser::class]);
        $c->validateAndFreeze();

        $user = $c->get(Audit305StringShiftUser::class);

        // Old plan: ['A', 'C'] -> new User('A', 'C') put 'A' (a string!)
        // into the string|int union slot and 'C' into label: silent garbage.
        self::assertSame('A', $user->first);
        self::assertSame('B', $user->mode, 'the union slot receives ITS OWN default, not the first argument');
        self::assertSame('C', $user->label);
    }

    public function testRequiredUnionStillFailsLoudly(): void
    {
        $c = new Container();

        $this->expectException(\Zef\Framework\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not autowireable');

        new AutowireCompilerPass()->process($c, [Audit305RequiredUnionUser::class]);
    }
}

/**
 * @internal
 */
final class Audit305MidUnionUser
{
    public function __construct(
        public readonly \stdClass $probe,
        public readonly int|string $mode = 'fallback',
        public readonly string $label = 'anon',
    ) {}
}

/**
 * @internal
 */
final class Audit305StringShiftUser
{
    public function __construct(
        public readonly string $first = 'A',
        public readonly int|string $mode = 'B',
        public readonly string $label = 'C',
    ) {}
}

/**
 * @internal
 */
final class Audit305RequiredUnionUser
{
    public function __construct(
        public readonly \stdClass $probe,
        public readonly int|float $ratio,
    ) {}
}
