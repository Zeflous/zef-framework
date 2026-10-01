<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Domain/Validation/RouteConstraintValidator.php (12 escaped).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * @internal
 */
final class RouteConstraintValidatorMutantKillTest extends TestCase
{
    /** ReDoS rejection is pinned (kills Throw_:59). */
    public function testReDoSRegexIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nested quantified groups are not permitted by the ReDoS safety policy.');
        new RouteConstraintValidator()->addCustom('evil', '(a+)+');
    }

    /** An invalid regex is converted to InvalidConfigurationException with code 0 (kills Increment/DecrementInteger:61/68). */
    public function testInvalidRegexIsWrappedWithCodeZero(): void
    {
        try {
            new RouteConstraintValidator()->addCustom('bad', '/(/');
            self::fail('an invalid regex must be rejected');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(0, $e->getCode(), 'the wrapping exception code must stay 0');
            self::assertStringContainsString("Invalid route constraint regex 'bad'", $e->getMessage());
        }
    }

    /** The uuid constraint is anchored at the start (kills PregMatchRemoveCaret:136). */
    public function testUuidConstraintIsAnchored(): void
    {
        $v = new RouteConstraintValidator();
        self::assertTrue($v->test('p', 'uuid', '12345678-1234-1234-1234-123456789012'));
        self::assertFalse($v->test('p', 'uuid', 'zzz12345678-1234-1234-1234-123456789012'), 'a leading prefix must not satisfy the uuid constraint');
        self::assertFalse($v->test('p', 'uuid', '12345678-1234-1234-1234-123456789012zzz'), 'a trailing suffix must not satisfy the uuid constraint');
    }

    /** Every built-in constraint accepts valid and rejects invalid input (kills MatchArmRemoval:130). */
    public function testBuiltInConstraints(): void
    {
        $v = new RouteConstraintValidator();
        self::assertTrue($v->test('p', 'int', '123'));
        self::assertFalse($v->test('p', 'int', '12a'));
        self::assertTrue($v->test('p', 'uint', '123'));
        self::assertFalse($v->test('p', 'uint', '0123'));
        self::assertTrue($v->test('p', 'alpha', 'abcXYZ'));
        self::assertFalse($v->test('p', 'alpha', 'abc1'));
        self::assertTrue($v->test('p', 'slug', 'a-b-c'));
        self::assertFalse($v->test('p', 'slug', 'A-B'));
        self::assertTrue($v->test('p', 'hex', 'deadBEEF'));
        self::assertFalse($v->test('p', 'hex', 'xyz'));
    }

    /** Unknown constraint types are rejected (kills MatchArmRemoval:130). */
    public function testUnknownConstraintTypeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Unknown route constraint type 'nope'.");
        new RouteConstraintValidator()->assertKnown('nope');
    }

    /** A custom constraint is compiled once and reused (kills AssignCoalesce:86). */
    public function testCustomConstraintIsCachedAndReused(): void
    {
        $v = new RouteConstraintValidator();
        $v->addCustom('even', '/^\d*[02468]$/');
        self::assertTrue($v->test('p', 'even', '42'));
        self::assertFalse($v->test('p', 'even', '43'));
        // Re-registering the same name invalidates the compiled cache and recompiles.
        $v->addCustom('even', '/^\d*[13579]$/');
        self::assertTrue($v->test('p', 'even', '43'));
        self::assertFalse($v->test('p', 'even', '42'));
    }
}
