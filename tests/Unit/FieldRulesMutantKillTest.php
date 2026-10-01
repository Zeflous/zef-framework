<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Domain/Validation/FieldRules.php (6 escaped).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Validation\FieldRules;

/**
 * @internal
 */
final class FieldRulesMutantKillTest extends TestCase
{
    /** typeString() emits the 'kind' => 'string' param (kills ArrayItemRemoval:57). */
    public function testTypeStringEmitsKindParam(): void
    {
        $defs = (new FieldRules('f'))->typeString()->definitions();
        self::assertSame('type', $defs[0]['rule']);
        self::assertSame(['kind' => 'string'], $defs[0]['params']);
    }

    /** minLength() emits the 'min' param (kills ArrayItemRemoval:90). */
    public function testMinLengthEmitsMinParam(): void
    {
        $defs = (new FieldRules('f'))->minLength(7)->definitions();
        self::assertSame('min_length', $defs[0]['rule']);
        self::assertSame(['min' => 7], $defs[0]['params']);
    }

    /** min() compares numerically for string-numeric input (kills CastFloat:112). */
    public function testMinComparesNumericStrings(): void
    {
        $rules = (new FieldRules('f'))->min(10);
        self::assertSame([], $rules->validate('10'), '10 >= 10 passes');
        self::assertSame([], $rules->validate('10.5'), '10.5 >= 10 passes');
        self::assertCount(1, $rules->validate('9.9'), '9.9 < 10 fails');
        self::assertCount(1, $rules->validate('9'), '9 < 10 fails');
    }

    /** max() compares numerically for string-numeric input (kills CastFloat:122). */
    public function testMaxComparesNumericStrings(): void
    {
        $rules = (new FieldRules('f'))->max(10);
        self::assertSame([], $rules->validate('10'), '10 <= 10 passes');
        self::assertSame([], $rules->validate('9.5'), '9.5 <= 10 passes');
        self::assertCount(1, $rules->validate('10.1'), '10.1 > 10 fails');
        self::assertCount(1, $rules->validate('11'), '11 > 10 fails');
    }

    /** A skipped null rule must not stop later rules (kills Continue_:224). */
    public function testSkipNullDoesNotStopLaterRules(): void
    {
        $rules = (new FieldRules('f'))->minLength(5)->required();
        $errors = $rules->validate(null);
        self::assertCount(1, $errors, 'the required rule after a skipped null rule must still run');
        self::assertSame('required', $errors[0]->rule);
    }

    /** A skipped empty rule must not stop later rules (kills Continue_:227). */
    public function testSkipEmptyDoesNotStopLaterRules(): void
    {
        $rules = (new FieldRules('f'))->minLength(5)->required();
        $errors = $rules->validate('');
        self::assertCount(1, $errors, 'the required rule after a skipped empty rule must still run');
        self::assertSame('required', $errors[0]->rule);
    }
}
