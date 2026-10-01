<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspots: RoutePatternParser.php (5), RouteRadixIndex.php (4), RouteMatcher.php (3), RouteGroupStack.php (1).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Router\RouteGroupStack;
use Zef\Framework\Router\RoutePatternParser;
use Zef\Framework\Router\RouteRadixIndex;
use Zef\Framework\Router\Router;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * @internal
 */
final class RouterInternalsMutantKillTest extends TestCase
{
    // ---- RoutePatternParser ----

    /** canonicalSignature renders dynamic-with-constraint as '*constraint' (kills Concat/ConcatOperandRemoval:93). */
    public function testCanonicalSignatureRendersSegments(): void
    {
        self::assertSame('GET|/a/*int', RoutePatternParser::canonicalSignature('GET', RoutePatternParser::parsePattern('/a/{id:int}')));
        self::assertSame('GET|/a/*', RoutePatternParser::canonicalSignature('GET', RoutePatternParser::parsePattern('/a/{id}')));
        self::assertSame('GET|/a/b', RoutePatternParser::canonicalSignature('GET', RoutePatternParser::parsePattern('/a/b')));
    }

    /** parsePattern('/') is the empty segment list (kills ReturnRemoval:78). */
    public function testParsePatternRootIsEmpty(): void
    {
        self::assertSame([], RoutePatternParser::parsePattern('/'));
    }

    /** Duplicate dynamic names are rejected even after a static segment (kills TrueValue:117, Continue_:169). */
    public function testAssertUniqueParamsRejectsDuplicateAfterStatic(): void
    {
        $segments = RoutePatternParser::parsePattern('/a/{x}/{x}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate route parameter 'x'.");
        RoutePatternParser::assertUniqueParams($segments, new RouteConstraintValidator());
    }

    public function testAssertUniqueParamsAcceptsDistinctNames(): void
    {
        RoutePatternParser::assertUniqueParams(
            RoutePatternParser::parsePattern('/a/{x}/{y}'),
            new RouteConstraintValidator(),
        );
        self::assertTrue(true, 'distinct dynamic names are accepted');
    }

    // ---- RouteRadixIndex ----

    /** @return list<array<string,mixed>> */
    private function records(string ...$patterns): array
    {
        $out = [];
        foreach ($patterns as $pattern) {
            $segments = RoutePatternParser::parsePattern($pattern);
            $out[] = [
                'method' => 'GET',
                'pattern' => $pattern,
                'handler' => 'h',
                'module' => null,
                'priority' => 0,
                'sequence' => 0,
                'segments' => $segments,
                'signature' => RoutePatternParser::canonicalSignature('GET', $segments),
                'staticCount' => 0,
                'constrainedCount' => 0,
                'name' => null,
                'middleware' => [],
            ];
        }

        return $out;
    }

    /** Static descent yields the route index (kills ReturnRemoval:75/129). */
    public function testRadixStaticDescent(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/b', '/a/c'));

        self::assertSame([0], $radix->candidates('/a/b', true, new RouteConstraintValidator()));
        self::assertSame([1], $radix->candidates('/a/c', true, new RouteConstraintValidator()));
        self::assertSame([], $radix->candidates('/a/z', true, new RouteConstraintValidator()), 'an unknown path yields no candidates');
    }

    /** Dynamic descent marks the child (kills TrueValue:108). */
    public function testRadixDynamicDescent(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/{id}'));

        self::assertSame([0], $radix->candidates('/a/anything', true, new RouteConstraintValidator()));
    }

    /** Constraint-keyed descent prunes non-matching values (kills Coalesce:51). */
    public function testRadixConstraintKeyedDescent(): void
    {
        $radix = new RouteRadixIndex();
        $radix->compile($this->records('/a/{id:int}'));

        self::assertSame([0], $radix->candidates('/a/5', true, new RouteConstraintValidator()));
        self::assertSame([], $radix->candidates('/a/x', true, new RouteConstraintValidator()), 'a value failing the constraint is pruned');
    }

    // ---- RouteMatcher ----

    /** A 405 carries the allowed methods incl. HEAD for a GET route (kills TrueValue:146/148). */
    public function testMethodNotAllowedAllowList(): void
    {
        $router = new Router();
        $router->add('GET', '/a', 'h.a');

        try {
            $router->match('POST', '/a');
            self::fail('POST to a GET-only route must be 405');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->allowedMethods);
            self::assertContains('HEAD', $e->allowedMethods, 'a GET route implies HEAD is allowed');
        }
    }

    /** A HEAD request to a constrained route with a bad value is a 400, not a 405 (kills Continue_:178). */
    public function testHeadConstraintFailureIs400(): void
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.a');

        $this->expectException(RouteConstraintException::class);
        $router->match('HEAD', '/a/xyz');
    }

    // ---- RouteGroupStack ----

    /** push() must stay public (kills PublicVisibility:119). */
    public function testPushIsPublic(): void
    {
        $method = new \ReflectionMethod(RouteGroupStack::class, 'push');
        self::assertTrue($method->isPublic(), 'RouteGroupStack::push() must stay public');
    }
}
