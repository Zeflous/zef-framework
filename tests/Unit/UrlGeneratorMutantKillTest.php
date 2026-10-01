<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Adapters/Router/UrlGenerator.php (5 escaped).
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
    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a', name: 'a');
        $router->add('GET', '/b', 'h.b', name: 'b');

        return $router;
    }

    /** The placeholder regex is anchored at both ends (kills PregMatchRemoveCaret/Dollar:70). */
    public function testPlaceholderRegexIsAnchored(): void
    {
        $generator = new UrlGenerator($this->router());
        $segment = \Closure::bind(
            function (string $name, string $part, array $params, array &$consumed): string {
                return $this->segment($name, $part, $params, $consumed);
            },
            $generator,
            UrlGenerator::class,
        );
        self::assertNotNull($segment);

        $consumed = [];
        self::assertSame('x{id}', $segment('r', 'x{id}', [], $consumed), 'a leading prefix must not be treated as a placeholder');
        self::assertSame('{id}x', $segment('r', '{id}x', [], $consumed), 'a trailing suffix must not be treated as a placeholder');
        self::assertSame('5', $segment('r', '{id}', ['id' => '5'], $consumed), 'an exact placeholder is substituted');
    }

    /** The non-scalar parameter message is pinned (kills ConcatOperandRemoval:98). */
    public function testNonScalarParamMessageIsPinned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route 'a' parameter 'id' must be scalar or Stringable, got array.");
        (new UrlGenerator($this->router()))->generate('a', ['id' => []]);
    }

    /** A generated URL round-trips through its own route (sanity for the substitution path). */
    public function testGenerateRoundTrips(): void
    {
        $router = $this->router();
        $url = (new UrlGenerator($router))->generate('a', ['id' => 42]);
        self::assertSame('/a/42', $url);
        self::assertSame('h.a', $router->match('GET', $url)['handler']);
    }
}
