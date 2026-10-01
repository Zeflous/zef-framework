<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Adapters/Router/UrlGenerator.php (5 escaped).
 *
 * NOTE: the placeholder-regex anchoring mutants (PregMatchRemoveCaret/Dollar:70)
 * are EQUIVALENT — RoutePatternParser rejects any partial placeholder such as
 * '/x{id}' at add() time, so segment() only ever sees a whole-segment '{name}'.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\UrlGenerator;

/**
 * @internal
 */
final class UrlGeneratorMutantKillTest extends TestCase
{
    /** The non-scalar parameter message is pinned (kills ConcatOperandRemoval:98). */
    public function testNonScalarParamMessageIsPinned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'a' parameter 'id' must be scalar or Stringable, got array.");
        new UrlGenerator($this->router())->generate('a', ['id' => []]); // @phpstan-ignore argument.type (deliberately non-scalar to exercise the guard)
    }

    /** A generated URL round-trips through its own route (sanity for the substitution path). */
    public function testGenerateRoundTrips(): void
    {
        $router = $this->router();
        $url = new UrlGenerator($router)->generate('a', ['id' => 42]);
        self::assertSame('/a/42', $url);
        self::assertSame('h.a', $router->match('GET', $url)['handler']);
    }

    /** A Stringable parameter is accepted and stringified (kills CastString:52). */
    public function testStringableParamIsAccepted(): void
    {
        $router = $this->router();
        $value = new class implements \Stringable {
            public function __toString(): string
            {
                return '99';
            }
        };
        self::assertSame('/a/99', new UrlGenerator($router)->generate('a', ['id' => $value]));
    }

    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a', name: 'a');
        $router->add('GET', '/b', 'h.b', name: 'b');

        return $router;
    }
}
