<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspots: ValidationTranslator.php (4), ValidationResult.php (2), Validator.php (1).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Validation\MessageCatalog;
use Zef\Framework\Validation\ValidationError;
use Zef\Framework\Validation\ValidationResult;
use Zef\Framework\Validation\ValidationTranslator;
use Zef\Framework\Validation\Validator;

/**
 * @internal
 */
final class ValidationMiscMutantKillTest extends TestCase
{
    // ---- ValidationTranslator (kills Continue_:37, ArrayItemRemoval:40, ArrayItem:41/43) ----

    public function testTranslateInterpolatesFieldLabelAndRule(): void
    {
        $catalog = MessageCatalog::empty()->with('en', [
            'min_length' => '{{label}} ({{field}}) failed {{rule}}.',
        ]);
        $translator = new ValidationTranslator($catalog);
        $result = new ValidationResult([new ValidationError('username', 'orig', 'min_length')], []);

        $translated = $translator->translate($result, 'en', ['username' => 'User Name']);

        self::assertSame('User Name (username) failed min_length.', $translated->messages()[0]);
    }

    public function testTranslateKeepsOriginalMessageForUnknownRule(): void
    {
        $translator = new ValidationTranslator(MessageCatalog::empty()->with('en', ['known' => 'x']));
        $result = new ValidationResult([new ValidationError('f', 'original message', 'unknown_rule')], []);

        $translated = $translator->translate($result, 'en');

        self::assertSame('original message', $translated->messages()[0], 'an unknown rule keeps the original message');
    }

    public function testTranslateReturnsSameResultWhenNoErrors(): void
    {
        $translator = new ValidationTranslator(MessageCatalog::defaultEnglish());
        $result = new ValidationResult([], ['a' => 1]);
        self::assertSame($result, $translator->translate($result, 'en'));
    }

    // ---- ValidationResult (kills ArrayOneItem:41/54) ----

    public function testMessagesReturnsEveryError(): void
    {
        $result = new ValidationResult([
            new ValidationError('a', 'm1', 'r'),
            new ValidationError('b', 'm2', 'r'),
            new ValidationError('c', 'm3', 'r'),
        ], []);
        self::assertSame(['m1', 'm2', 'm3'], $result->messages());
    }

    public function testErrorsForReturnsEveryMatchingError(): void
    {
        $result = new ValidationResult([
            new ValidationError('a', 'm1', 'r'),
            new ValidationError('a', 'm2', 'r'),
            new ValidationError('b', 'm3', 'r'),
        ], []);
        self::assertCount(2, $result->errorsFor('a'));
        self::assertCount(1, $result->errorsFor('b'));
        self::assertSame([], $result->errorsFor('c'));
    }

    // ---- Validator (kills GreaterThan:36) ----

    public function testFieldNameBoundary128Accepted129Rejected(): void
    {
        $validator = new Validator();
        $name128 = str_repeat('a', 128);
        $validator->field($name128);
        self::assertTrue($validator->hasField($name128), 'a 128-byte field name is accepted');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Field name must be 1..128 bytes.');
        $validator->field(str_repeat('a', 129));
    }
}
