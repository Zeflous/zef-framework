<?php

declare(strict_types=1);

/*
 * ZEF Framework — Regression coverage for ZEF-DEEP-04 (issue #158):
 * dynamic route segments must be rawurldecode()d BEFORE constraint testing
 * and BEFORE reaching handler attributes.
 *
 * Pre-fix, `GET /users/john%20doe` delivered the attribute "john%20doe"
 * (still encoded), and `GET /users/%31%32%33` FAILED the {id:int}
 * constraint because the test ran against the encoded text. The matcher and
 * UrlGenerator were asymmetric: the generator rawurlencode()s every dynamic
 * value, the matcher never decoded it back.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\UrlGenerator;

/**
 * @internal
 */
final class RouterParamDecodingTest extends TestCase
{
    public function testPercentEncodedSpacesDecodeInParameterValues(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{name}', 'users.show');

        $match = $router->match('GET', '/users/john%20doe');
        self::assertSame('users.show', $match['handler']);
        self::assertSame('john doe', $match['params']['name'], 'the attribute must be the decoded value');
    }

    /** The audit PoC: an encoded-valid integer must satisfy {id:int}. */
    public function testEncodedDigitsSatisfyIntConstraintAfterDecoding(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id:int}', 'users.show');

        $match = $router->match('GET', '/users/%31%32%33');
        self::assertSame('users.show', $match['handler']);
        self::assertSame('123', $match['params']['id'], '%31%32%33 is "123" after one decode level');
    }

    public function testEncodedSlashDecodesInsideASegment(): void
    {
        $router = new Router();
        $router->add('GET', '/files/{path}', 'files.get');

        // %2F stays inside ONE path segment (the raw path splits on '/'),
        // and decodes into the parameter value.
        $match = $router->match('GET', '/files/report%2Ffinal');
        self::assertSame('report/final', $match['params']['path']);
    }

    public function testUtf8PercentSequencesDecode(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{name}', 'users.show');

        // 'jose' with an acute accent over the e: jos%C3%A9.
        $match = $router->match('GET', '/users/jos%C3%A9');
        self::assertSame('josé', $match['params']['name']);
    }

    /** "+" is a literal plus in PATH components — only query strings read it as space. */
    public function testPlusStaysLiteralBecauseDecodingIsRaw(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{name}', 'users.show');

        $match = $router->match('GET', '/users/ze+bra');
        self::assertSame('ze+bra', $match['params']['name'], 'rawurldecode (not urldecode) must be used');
    }

    /** Static segments keep comparing against the RAW path text (audit's contract). */
    public function testStaticSegmentsAreNotDecodedForComparison(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{name}', 'users.show');

        // '%75sers' would decode to 'users' — a static segment must not.
        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/%75sers/john');
    }

    /** Constraints still reject values that are invalid AFTER decoding. */
    public function testConstraintsTestTheDecodedValue(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id:int}', 'users.show');

        // '%31%32%61' decodes to '12a' — invalid for {id:int}: the radix
        // constraint filter AND matchRoute must both reject it.
        $this->expectException(RouteConstraintException::class);
        $router->match('GET', '/users/%31%32%61');
    }

    /** Exactly one level of decoding: %2520 becomes '%20', not a space. */
    public function testDecodingHappensExactlyOnce(): void
    {
        $router = new Router();
        $router->add('GET', '/search/{q}', 'search');

        $match = $router->match('GET', '/search/%2520');
        self::assertSame('%20', $match['params']['q']);
    }

    /** Round-trip symmetry: generate() encodes, match() decodes. */
    public function testUrlGeneratorRoundTripIsSymmetric(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{name}', 'users.show', null, 0, 'users.show');
        $generator = new UrlGenerator($router);

        $url = $generator->generate('users.show', ['name' => 'john doe']);
        self::assertSame('/users/john%20doe', $url);

        $match = $router->match('GET', $url);
        self::assertSame('john doe', $match['params']['name'], 'generate -> match must return the original value');
    }
}
