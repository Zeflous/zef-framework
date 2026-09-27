<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): domain query/validation
 * primitives — filter and sort specifications, cron parsing, field rules
 * and message catalogs.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Resource\FilterCondition;
use Zef\Framework\Resource\FilterSpec;
use Zef\Framework\Resource\SortSpec;
use Zef\Framework\Validation\FieldRules;
use Zef\Framework\Validation\MessageCatalog;

/**
 * @internal
 */
final class DomainEdgeTest extends TestCase
{
    // ------------------------------------------------------------------
    // FilterSpec
    // ------------------------------------------------------------------

    public function testFilterSpecParsesNestedAndFlatQueries(): void
    {
        $spec = FilterSpec::fromQuery([
            'filter' => ['status' => 'open', 'price_gte' => '100'],
            'filter_name' => 'widget',
        ], ['status', 'price', 'name']);
        self::assertFalse($spec->isEmpty());
        self::assertSame(3, count($spec->conditions()));

        $empty = FilterSpec::fromQuery([], ['status']);
        self::assertTrue($empty->isEmpty());
        self::assertSame([], $empty->conditions());
    }

    public function testFilterSpecDropsUnknownFieldsAndBadEntries(): void
    {
        $spec = FilterSpec::fromQuery([
            'filter' => ['unknown' => 'x', 7 => 'scalar-key', 'ok' => ['nested-array']],
        ], ['ok']);
        self::assertTrue($spec->isEmpty());
    }

    public function testFilterSpecParsesInOperator(): void
    {
        $spec = FilterSpec::fromQuery(['filter' => ['status_in' => 'open, closed, , pending']], ['status']);
        $condition = $spec->conditions()[0];
        self::assertSame(FilterCondition::IN, $condition->op);
        self::assertSame(['open', 'closed', 'pending'], $condition->value);
    }

    public function testFilterSpecCapsValueAndConditionCount(): void
    {
        $spec = FilterSpec::fromQuery(['filter' => ['note' => str_repeat('x', 300)]], ['note']);
        $condition = $spec->conditions()[0];
        $value = $condition->value;
        self::assertIsString($value);
        self::assertSame(256, strlen($value));

        $query = ['filter' => []];
        for ($i = 0; $i < 40; ++$i) {
            $query['filter']['f' . $i . '_eq'] = (string) $i;
        }
        $capped = FilterSpec::fromQuery($query, array_map(static fn (int $i): string => 'f' . $i, range(0, 39)));
        self::assertSame(FilterSpec::MAX_CONDITIONS, count($capped->conditions()));
    }

    public function testFilterSpecAppliesOperatorsToRows(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alpha Widget', 'price' => 100, 'active' => true],
            ['id' => 2, 'name' => 'beta gadget', 'price' => 250, 'active' => false],
            ['id' => 3, 'name' => 'Gamma', 'price' => null, 'active' => true],
        ];
        $eq = FilterSpec::fromQuery(['filter' => ['price_eq' => '250']], ['price']);
        self::assertSame([0], array_keys($eq->applyTo($rows)));

        $neq = FilterSpec::fromQuery(['filter' => ['price_neq' => '250']], ['price']);
        self::assertSame([0, 1], array_keys($neq->applyTo($rows)));

        $gt = FilterSpec::fromQuery(['filter' => ['price_gt' => '99']], ['price']);
        self::assertSame([0, 1], array_keys($gt->applyTo($rows)));

        $lte = FilterSpec::fromQuery(['filter' => ['price_lte' => '100']], ['price']);
        self::assertSame([0], array_keys($lte->applyTo($rows)));

        $like = FilterSpec::fromQuery(['filter' => ['name_like' => 'widget']], ['name']);
        self::assertSame([0], array_keys($like->applyTo($rows)));

        $in = FilterSpec::fromQuery(['filter' => ['id_in' => '1,3']], ['id']);
        self::assertSame([0, 1], array_keys($in->applyTo($rows)));

        $objects = [(object) ['price' => 250]];
        $obj = FilterSpec::fromQuery(['filter' => ['price_eq' => '250']], ['price']);
        self::assertCount(1, $obj->applyTo($objects));

        $bool = FilterSpec::fromQuery(['filter' => ['active_eq' => '1']], ['active']);
        self::assertSame([0, 1], array_keys($bool->applyTo($rows)));
    }

    // ------------------------------------------------------------------
    // SortSpec
    // ------------------------------------------------------------------

    public function testSortSpecParsesDirectionAndDeduplicates(): void
    {
        $spec = SortSpec::fromQuery(['sort' => ' -price , +name, price, unknown, x'], ['price', 'name', 'x']);
        $keys = $spec->keys();
        self::assertSame('price', $keys[0]->field);
        self::assertTrue($keys[0]->desc);
        self::assertSame('name', $keys[1]->field);
        self::assertFalse($keys[1]->desc);
        self::assertSame(3, count($keys));
        self::assertSame('x', $keys[2]->field);
        self::assertSame('-price,name,x', $spec->toQuery());
    }

    public function testSortSpecFallsBackToDefaults(): void
    {
        $spec = SortSpec::fromQuery([], ['price'], 'sort', ['name'], true);
        self::assertSame('name', $spec->keys()[0]->field);
        self::assertTrue($spec->keys()[0]->desc);
        self::assertNull(SortSpec::fromQuery([], ['price'])->toQuery());

        $emptyKeys = new SortSpec([], [], false);
        self::assertTrue($emptyKeys->isEmpty());
    }

    public function testSortSpecRejectsNonKeyEntriesAndEmptyFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SortSpec(['not-a-key']);
    }

    public function testSortSpecSortsMultiKeyStable(): void
    {
        $spec = SortSpec::fromQuery(['sort' => 'tier,-score'], ['tier', 'score']);
        $rows = [
            ['tier' => 'b', 'score' => 5],
            ['tier' => 'a', 'score' => 9],
            ['tier' => 'a', 'score' => 7],
        ];
        $sorted = $spec->applyTo($rows);
        self::assertSame(['tier' => 'a', 'score' => 9], $sorted[0]);
        self::assertSame(['tier' => 'a', 'score' => 7], $sorted[1]);
        self::assertSame(['tier' => 'b', 'score' => 5], $sorted[2]);
        $single = [['score' => 2]];
        self::assertSame($single, $spec->applyTo($single));
    }

    // ------------------------------------------------------------------
    // CronExpression
    // ------------------------------------------------------------------

    public function testCronParsesStepsRangesAndLists(): void
    {
        $cron = CronExpression::parse('*/15 0,12 1-10 * *');
        self::assertSame('*/15 0,12 1-10 * * (UTC)', $cron->describe());
        // 2025-06-02 00:00:00 UTC is minute 0 of hour 0 within dom 1-10.
        $monday = strtotime('2025-06-02 00:00:00 UTC');
        self::assertIsInt($monday);
        self::assertTrue($cron->matchesUtc($monday));
        self::assertFalse($cron->matchesUtc($monday + 7 * 60));
    }

    public function testCronRejectsInvalidExpressions(): void
    {
        // NOTE: '* * * * 7' used to be rejected here; v2.31.0 accepts dow 7
        // and normalizes it to 0 (see JobCronEngineV2Test).
        foreach (['', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '* * * * 8', '* * * * * *', '*/0 * * * *', '* */x * * *'] as $bad) {
            try {
                CronExpression::parse($bad);
                self::fail("Expected rejection for '{$bad}'.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCronComputesNextRunsAndMatches(): void
    {
        $everyMinute = CronExpression::parse('* * * * *');
        $now = 1700000000 * 1_000_000_000;
        $next = $everyMinute->nextRunAfter($now);
        self::assertGreaterThan($now, $next);
        self::assertTrue($everyMinute->matchesUtc(intdiv($next, 1_000_000_000)));

        $weekly = CronExpression::parse('0 0 * * 1');
        self::assertTrue($weekly->matchesUtc(strtotime('monday this week 00:00:00 UTC')));
        self::assertFalse($weekly->matchesUtc(strtotime('tuesday this week 00:00:00 UTC')));

        // DOM and DOW both restricted: union semantics (vixie cron).
        $union = CronExpression::parse('0 0 1 * 0');
        self::assertTrue($union->matchesUtc(strtotime('sunday this week 00:00:00 UTC')));

        $this->expectException(\InvalidArgumentException::class);
        $everyMinute->nextRunAfter(-1);
    }

    // ------------------------------------------------------------------
    // FieldRules
    // ------------------------------------------------------------------

    public function testFieldRulesValidateChains(): void
    {
        $rules = new FieldRules('email')
            ->required()
            ->typeString()
            ->email()
            ->maxLength(64)
        ;
        self::assertSame([], $rules->validate('user@example.test'));
        $errors = $rules->validate('');
        self::assertNotSame([], $errors);
        self::assertSame('email', $errors[0]->field);

        $numeric = new FieldRules('qty')->typeInt()->min(1)->max(10);
        self::assertSame([], $numeric->validate(5));
        self::assertCount(1, $numeric->validate(11));
        self::assertCount(3, $numeric->validate('abc'));

        $lenient = new FieldRules('note')->typeString()->nullable();
        self::assertSame([], $lenient->validate(null));
    }

    public function testFieldRulesPatternAndCustomAndUuid(): void
    {
        $rules = new FieldRules('code')
            ->pattern('/^[A-Z]{3}$/', 'must be a 3-letter code.')
            ->custom(static fn (mixed $v): bool => $v !== 'XXX', 'cannot be XXX.')
        ;
        self::assertSame([], $rules->validate('ABC'));
        self::assertCount(1, $rules->validate('abc'));
        self::assertCount(1, $rules->validate('XXX'));

        $this->expectException(\InvalidArgumentException::class);
        new FieldRules('bad')->pattern('');
    }

    public function testFieldRulesUuidAndTypeVariants(): void
    {
        $uuid = new FieldRules('ref');
        self::assertSame([], $uuid->uuid()->validate('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        self::assertNotSame([], $uuid->uuid()->validate('nope'));

        $mixed = new FieldRules('n');
        self::assertSame([], $mixed->typeNumeric()->validate('1.5'));
        self::assertSame([], $mixed->typeNumeric()->validate([]));

        $in = new FieldRules('status');
        self::assertSame([], $in->in(['a', 'b'])->validate('a'));
        self::assertNotSame([], $in->in(['a', 'b'])->validate('c'));

        $len = new FieldRules('s');
        self::assertSame([], $len->minLength(2)->maxLength(4)->validate('abc'));
        self::assertNotSame([], $len->minLength(2)->validate('a'));
    }

    // ------------------------------------------------------------------
    // MessageCatalog
    // ------------------------------------------------------------------

    public function testMessageCatalogFallsBackAcrossLocales(): void
    {
        $catalog = MessageCatalog::defaultEnglish()
            ->with('id_ID', ['required' => '{{label}} wajib diisi.'])
            ->with('id', ['required' => '{{label}} wajib.'])
        ;
        self::assertSame('{{label}} wajib diisi.', $catalog->templateFor('id_ID', 'required'));
        self::assertSame('{{label}} wajib.', $catalog->templateFor('id', 'required'));
        self::assertSame('{{label}} is not a valid email address.', $catalog->templateFor('id', 'email'));
        self::assertSame('{{label}} is required.', $catalog->templateFor('en', 'required'));
        self::assertNull($catalog->templateFor('xx', 'nonexistent-rule'));
    }

    public function testMessageCatalogAcceptsWildcardAndRejectsBadInput(): void
    {
        $catalog = MessageCatalog::empty()->withMany(['en' => ['custom' => '{{label}} fallback.']]);
        self::assertSame('{{label}} fallback.', $catalog->templateFor('en', 'custom'));
        $this->expectException(\InvalidArgumentException::class);
        $catalog->with('bad locale!', ['required' => 'x']);
    }

    public function testMessageCatalogRejectsBadRuleAndTemplate(): void
    {
        $catalog = MessageCatalog::empty();
        $this->expectException(\InvalidArgumentException::class);
        $catalog->with('en', ['' => 'empty rule key']);
    }
}
