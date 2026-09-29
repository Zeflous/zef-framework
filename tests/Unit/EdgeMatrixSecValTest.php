<?php

declare(strict_types=1);

/*
 * ZEF Framework — Edge-Case Matrix tier 1 (v2.14.2): negative and boundary
 * assertions for the Domain/Validation cluster. Each test names the adversarial
 * scenario it pins down; escaped-mutant evidence lives in build/escapes-*.txt
 * and the curriculum in docs/EDGE-CASE-MATRIX.md.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\CircularAliasException;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Validation\DependencyGraphValidator;
use Zef\Framework\Validation\FieldRules;
use Zef\Framework\Validation\HttpMethodValidator;
use Zef\Framework\Validation\HttpStatusValidator;
use Zef\Framework\Validation\Identifier;
use Zef\Framework\Validation\MessageCatalog;
use Zef\Framework\Validation\PortRangeValidator;
use Zef\Framework\Validation\RouteConstraintValidator;
use Zef\Framework\Validation\TrustedHostValidator;
use Zef\Framework\Validation\ValidationError;
use Zef\Framework\Validation\ValidationResult;
use Zef\Framework\Validation\ValidationTranslator;
use Zef\Framework\Validation\Validator;

/**
 * @internal
 */
final class EdgeMatrixSecValTest extends TestCase
{
    // --------------------------------------------------------------- #
    // RouteConstraintValidator — ReDoS policy, error-handler hygiene,  #
    // regex anchors/flags on every built-in arm.                       #
    // --------------------------------------------------------------- #

    public function testAddCustomRejectsEmptyAndMalformedNames(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['', '1bad', 'bad-name', 'bad name', 'bad.name'] as $name) {
            try {
                $v->addCustom($name, '/^[a-z]+$/');
                self::fail("name '{$name}' must be rejected.");
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString("Invalid route constraint name '{$name}'", $e->getMessage());
            }
        }
        $v->addCustom('_ok1', '/^[a-z]+$/');
        self::assertTrue($v->test('p', '_ok1', 'abc'));
    }

    public function testAddCustomRegexLengthBoundaryIsInclusive(): void
    {
        $v = new RouteConstraintValidator();
        // '/^' + N bytes + '/' => total length N + 3; 2045 bytes == 2048 exactly.
        $atLimit = '/^' . str_repeat('a', 2045) . '/';
        $v->addCustom('limit', $atLimit);
        self::assertTrue($v->test('p', 'limit', str_repeat('a', 2045)));

        try {
            $v->addCustom('toolong', '/^' . str_repeat('a', 2046) . '/');
            self::fail('regex above 2048 bytes must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('pattern length must be between 1 and 2048 bytes', $e->getMessage());
        }
    }

    public function testAddCustomRejectsEmptyRegexAndNestedQuantifiers(): void
    {
        $v = new RouteConstraintValidator();

        try {
            $v->addCustom('empty', '');
            self::fail('empty regex must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('between 1 and 2048 bytes', $e->getMessage());
        }
        foreach (['/^(a+)+$/', '/^(?:\d+)*$/', '/^(?:[a-z]+)+$/'] as $redos) {
            try {
                $v->addCustom('boom', $redos);
                self::fail("nested quantifier '{$redos}' must be rejected.");
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('ReDoS safety policy', $e->getMessage());
            }
        }
    }

    public function testAddCustomRejectsBrokenRegexThroughErrorHandler(): void
    {
        $v = new RouteConstraintValidator();

        try {
            $v->addCustom('broken', '/[a-');
            self::fail('uncompilable regex must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("Invalid route constraint regex 'broken'", $e->getMessage());
            self::assertStringContainsString('No ending delimiter', $e->getMessage());
        }
    }

    public function testNullBytePatternIsTreatedAsLiteralByte(): void
    {
        // Pinned adversarial behavior (verified on PHP 8.4): PCRE2 compiles a
        // raw NUL byte as a literal byte — '/^a<NUL>/' only matches subjects
        // carrying 'a' followed by NUL; no truncation, no ValueError.
        // The ValueError catch in addCustom() stays defensive inventory.
        $v = new RouteConstraintValidator();
        $v->addCustom('nul', "/^a\x00/");
        self::assertTrue($v->test('p', 'nul', "a\x00"));
        self::assertTrue($v->test('p', 'nul', "a\x00b"), 'NUL is literal: anchor semantics still apply to ^ only');
        self::assertFalse($v->test('p', 'nul', 'abc'));
        self::assertFalse($v->test('p', 'nul', 'a'));
    }

    public function testFailedAddCustomRestoresGlobalErrorHandler(): void
    {
        $v = new RouteConstraintValidator();
        $probeCalled = false;
        $probe = static function () use (&$probeCalled): bool {
            $probeCalled = true;

            return true;
        };
        $previous = set_error_handler($probe);

        try {
            try {
                $v->addCustom('boom', '/[a-');
                self::fail('InvalidConfigurationException expected.');
            } catch (InvalidConfigurationException) {
                // expected
            }

            // The finally clause must have unwound ZEF's throwing handler back
            // to our probe; a leaked handler would rethrow as ErrorException.
            try {
                trigger_error('probe-warning', E_USER_WARNING);
                self::assertTrue($probeCalled, 'error handler restored to caller chain');
            } catch (\ErrorException) {
                self::fail('error handler leaked: finally did not restore the previous handler');
            }
        } finally {
            restore_error_handler();
            if ($previous !== null) {
                set_error_handler($previous);
            }
        }
    }

    public function testBuiltInIntArmBoundaryAndAnchors(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['0', '00', '9', '12'] as $good) {
            self::assertTrue($v->test('p', 'int', $good), "int '{$good}' must match");
        }
        foreach (['', '12a', 'a12', '-1', '1.5', "12\n", '١٢', ' ', '+1'] as $bad) {
            self::assertFalse($v->test('p', 'int', $bad), "int '{$bad}' must not match");
        }
    }

    public function testBuiltInUintArmRejectsLeadingZero(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['1', '10', '99'] as $good) {
            self::assertTrue($v->test('p', 'uint', $good), "uint '{$good}' must match");
        }
        foreach (['0', '00', '01', '', '-1', '1a', "1\n", '1.0'] as $bad) {
            self::assertFalse($v->test('p', 'uint', $bad), "uint '{$bad}' must not match");
        }
    }

    public function testBuiltInAlphaArmIsAsciiOnly(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['a', 'AbC', 'xyzABC'] as $good) {
            self::assertTrue($v->test('p', 'alpha', $good), "alpha '{$good}' must match");
        }
        foreach (['', 'ab1', 'ab-cd', "ab\n", 'ä', 'ábc', 'a b'] as $bad) {
            self::assertFalse($v->test('p', 'alpha', $bad), "alpha '{$bad}' must not match");
        }
    }

    public function testBuiltInSlugArmBoundary(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['a', 'a-b', 'a-b-c', '0-9', '0a'] as $good) {
            self::assertTrue($v->test('p', 'slug', $good), "slug '{$good}' must match");
        }
        foreach (['', 'A', 'a--b', '-a', 'a-', 'a-b-', "ab\n", 'a b', 'a_b'] as $bad) {
            self::assertFalse($v->test('p', 'slug', $bad), "slug '{$bad}' must not match");
        }
    }

    public function testBuiltInUuidArmShapeAndCaseInsensitivity(): void
    {
        $v = new RouteConstraintValidator();
        $nil = '00000000-0000-0000-0000-000000000000';
        $v4 = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';
        foreach ([$nil, $v4, strtoupper($v4)] as $good) {
            self::assertTrue($v->test('p', 'uuid', $good), "uuid '{$good}' must match");
        }
        foreach (['', '3f2504e0-4f89-11d3-9a0c-0305e82c330', $v4 . 'x', strtoupper($v4) . "\n", 'g2504e0-4f89-11d3-9a0c-0305e82c3301'] as $bad) {
            self::assertFalse($v->test('p', 'uuid', $bad), "uuid '{$bad}' must not match");
        }
    }

    public function testBuiltInHexArmBoundary(): void
    {
        $v = new RouteConstraintValidator();
        foreach (['de', 'DEADBEEF', '0', 'abcdef'] as $good) {
            self::assertTrue($v->test('p', 'hex', $good), "hex '{$good}' must match");
        }
        foreach (['', '0x1', 'xyz', "de\n", 'de ad'] as $bad) {
            self::assertFalse($v->test('p', 'hex', $bad), "hex '{$bad}' must not match");
        }
    }

    public function testAssertThrowsConstraintExceptionCarryingParamTypeValue(): void
    {
        $v = new RouteConstraintValidator();

        try {
            $v->assert('id', 'int', 'abc');
            self::fail('failing constraint must throw RouteConstraintException.');
        } catch (RouteConstraintException $e) {
            self::assertSame('id', $e->param);
            self::assertSame('int', $e->type);
            self::assertSame('abc', $e->value);
            self::assertStringContainsString("Route param 'id' failed constraint 'int'", $e->getMessage());
        }
    }

    public function testAssertPassesWhenConstraintMatches(): void
    {
        $v = new RouteConstraintValidator();
        $v->assert('page', 'uint', '42');
        self::assertTrue($v->test('page', 'uint', '42'));
    }

    public function testUnknownConstraintTypeIsRejectedEverywhere(): void
    {
        $v = new RouteConstraintValidator();

        try {
            $v->test('p', 'nope', '1');
            self::fail('unknown type must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("Unknown route constraint type 'nope'", $e->getMessage());
        }

        try {
            $v->assertKnown('nope');
            self::fail('assertKnown must reject unknown types.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("Unknown route constraint type 'nope'", $e->getMessage());
        }
    }

    public function testCustomConstraintIsRecompiledAfterRedefinition(): void
    {
        $v = new RouteConstraintValidator();
        $v->addCustom('kind', '/^[a-z]+$/');
        self::assertTrue($v->test('p', 'kind', 'abc'));
        self::assertFalse($v->test('p', 'kind', 'ABC'));
        // Same name registered again: the compiled cache entry must be dropped.
        $v->addCustom('kind', '/^[A-Z]+$/');
        self::assertTrue($v->test('p', 'kind', 'ABC'));
        self::assertFalse($v->test('p', 'kind', 'abc'));
        // Redefinition replaces the stored regex (name-keyed map).
        self::assertSame(['/^[A-Z]+$/'], array_values($v->customConstraints()));
    }

    // --------------------------------------------------------------- #
    // MessageCatalog — locale chain, byte boundaries, immutability.    #
    // --------------------------------------------------------------- #

    public function testWithEnforcesRuleAndTemplateByteBounds(): void
    {
        $longRule = str_repeat('r', 64);
        $atLimit = MessageCatalog::empty()->with('en', [$longRule => str_repeat('t', 512)]);
        self::assertNotNull($atLimit->templateFor('en', $longRule));

        foreach ([
            ['' => 'x'],
            [str_repeat('r', 65) => 'x'],
            [5 => 'x'],
            ['req' => ''],
            ['req' => str_repeat('t', 513)],
            ['req' => 7],
        ] as $badRules) {
            try {
                // @phpstan-ignore argument.type (the wrong shapes ARE the scenario)
                MessageCatalog::empty()->with('en', $badRules);
                self::fail('out-of-bounds rule/template must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertThat(
                    $e->getMessage(),
                    self::logicalOr(
                        self::stringContains('Catalog rule keys must be 1..64 byte strings.'),
                        self::stringContains("Catalog template for 'en/req' must be 1..512 bytes."),
                    ),
                );
            }
        }
    }

    public function testWithNormalizesLocaleByTrimAndLowercase(): void
    {
        $catalog = MessageCatalog::empty()->with('  EN_us  ', ['req' => '{{label}} is required.']);
        // Stored under the normalized key 'en_us'; templateFor() re-normalizes
        // its own argument through the same locale chain.
        self::assertNotNull($catalog->templateFor('en_us', 'req'));
        self::assertNotNull($catalog->templateFor('EN_US', 'req'));
    }

    public function testWithRejectsInvalidLocaleWithHint(): void
    {
        try {
            MessageCatalog::empty()->with('bad!', ['req' => 'x']);
            self::fail('invalid locale must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Invalid locale 'bad!'", $e->getMessage());
            self::assertStringContainsString("Expected e.g. 'id', 'en', 'id_ID'", $e->getMessage());
        }
    }

    public function testWithMergesWithoutMutatingTheOriginal(): void
    {
        $base = MessageCatalog::empty();
        $id = $base->with('id', ['required' => '{{label}} wajib diisi.']);
        self::assertNotNull($id->templateFor('id', 'required'));
        self::assertNull($base->templateFor('id', 'required'), 'original catalog must stay untouched');
        $id2 = $id->with('id', ['required' => 'ISI: {{label}}.']);
        self::assertSame('ISI: {{label}}.', $id2->templateFor('id', 'required'));
    }

    public function testWithManyMergesLocalesAndIgnoresNonArrayRuleSets(): void
    {
        $catalog = MessageCatalog::empty();
        // @phpstan-ignore argument.type (a non-array rule set IS the scenario)
        $catalog = $catalog->withMany([
            'en' => ['required' => 'A'],
            'id' => ['required' => 'B'],
            'zz' => 'not-an-array',
        ]);
        self::assertSame('A', $catalog->templateFor('en', 'required'));
        self::assertSame('B', $catalog->templateFor('id', 'required'));
        self::assertNull($catalog->templateFor('zz', 'required'));
    }

    public function testTemplateForFallsBackExactThenBaseThenWildcard(): void
    {
        // Wildcard templates only enter through defaultEnglish() — with()
        // refuses '*' as a user locale, so use the shipped default here.
        $wildcardOnly = MessageCatalog::defaultEnglish();
        self::assertSame('{{label}} is required.', $wildcardOnly->templateFor('id_ID', 'required'));

        $baseOnly = MessageCatalog::empty()->with('id', ['required' => 'B']);
        self::assertSame('B', $baseOnly->templateFor('id_ID', 'required'));
        self::assertNull($baseOnly->templateFor('en_ID', 'required'));

        $exact = $baseOnly->with('id_ID', ['required' => 'E']);
        self::assertSame('E', $exact->templateFor('id_ID', 'required'));
        self::assertSame('B', $exact->templateFor('id_SG', 'required'));

        self::assertNull($exact->templateFor('id_ID', 'pattern'));
    }

    public function testLocaleChainIsNormalizedAndDeduplicated(): void
    {
        self::assertSame(['id_id', 'id', '*'], MessageCatalog::localeChain('id_ID'));
        self::assertSame(['en', '*'], MessageCatalog::localeChain('EN'));
        self::assertSame(['en', '*'], MessageCatalog::localeChain('  EN  '), 'locale chain must trim');
        self::assertSame(['*'], MessageCatalog::localeChain('bad!'));
    }

    public function testLocaleLengthBoundaries(): void
    {
        // with() is immutable — capture the returned catalog at every step.
        $catalog = MessageCatalog::empty();
        $catalog = $catalog->with('abcdefgh', ['req' => 'x']); // 8 chars: valid
        $catalog = $catalog->with('en_12345678', ['req' => 'x']); // 8-char segment: valid
        $rejected = 0;
        foreach (['e', 'abcdefghi', 'en_U', 'en_123456789', 'en_'] as $bad) {
            try {
                $catalog->with($bad, ['req' => 'x']);
                self::fail("locale '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(5, $rejected, 'all five malformed locales must be rejected');
        self::assertNotNull($catalog->templateFor('abcdefgh', 'req'));
        self::assertNotNull($catalog->templateFor('en_12345678', 'req'));
    }

    // --------------------------------------------------------------- #
    // DependencyGraphValidator — alias chains, cycles, budgets.        #
    // --------------------------------------------------------------- #

    public function testResolveAliasFollowsChainAndRejectsCycles(): void
    {
        $g = new DependencyGraphValidator();
        self::assertSame('c', $g->resolveAlias('a', ['a' => 'b', 'b' => 'c']));

        try {
            $g->resolveAlias('a', ['a' => 'b', 'b' => 'a']);
            self::fail('alias cycle must be rejected.');
        } catch (CircularAliasException $e) {
            self::assertStringContainsString('Circular alias detected', $e->getMessage());
            self::assertStringContainsString('a -> b -> a', $e->getMessage());
        }

        try {
            $g->resolveAlias('a', ['a' => 'a']);
            self::fail('self-alias must be rejected.');
        } catch (CircularAliasException $e) {
            self::assertStringContainsString('a -> a', $e->getMessage());
        }
    }

    public function testResolveAliasRejectsEmptyAndNonStringTargets(): void
    {
        $g = new DependencyGraphValidator();

        try {
            $g->resolveAlias('a', ['a' => '']);
            self::fail('empty alias target must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("Alias 'a' must target a non-empty service ID.", $e->getMessage());
        }

        try {
            $g->resolveAlias('a', ['a' => 7]);
            self::fail('non-string alias target must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("Alias 'a' must target a non-empty service ID.", $e->getMessage());
        }
    }

    public function testValidateRejectsUnknownDependencyAndAliasTargets(): void
    {
        $g = new DependencyGraphValidator();

        try {
            $g->validate(['svc' => 'factory'], [], ['svc' => ['ghost']], ['svc' => 'M1']);
            self::fail('missing dependency must be rejected.');
        } catch (ServiceNotFoundException $e) {
            self::assertStringContainsString("Service 'ghost' not found", $e->getMessage());
        }

        try {
            $g->validate(['svc' => 'factory'], ['alias' => 'ghost'], [], ['svc' => 'M1', 'alias' => 'M1']);
            self::fail('alias pointing outside the factory set must be rejected.');
        } catch (ServiceNotFoundException $e) {
            self::assertStringContainsString("Service 'alias' not found", $e->getMessage());
            self::assertStringContainsString("module: 'M1'", $e->getMessage());
        }
    }

    public function testValidateRejectsSingletonDependingOnTransient(): void
    {
        $g = new DependencyGraphValidator();

        try {
            $g->validate(
                ['a' => 'fa', 'b' => 'fb'],
                [],
                ['a' => ['b']],
                ['a' => 'M1', 'b' => 'M1'],
                ['a' => ServiceLifetime::SINGLETON, 'b' => ServiceLifetime::REQUEST],
            );
            self::fail('singleton depending on request-scoped service must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("Singleton service 'a' transitively depends on request service 'b'", $e->getMessage());
        }
    }

    public function testValidateAllowsTransientDependingOnSingleton(): void
    {
        $g = new DependencyGraphValidator();
        $g->validate(
            ['a' => 'fa', 'b' => 'fb'],
            [],
            ['a' => ['b']],
            ['a' => 'M1', 'b' => 'M1'],
            ['a' => ServiceLifetime::REQUEST, 'b' => ServiceLifetime::SINGLETON],
        );
        self::expectNotToPerformAssertions();
    }

    public function testCrossModuleBudgetBoundaryAndDeduplication(): void
    {
        $g = new DependencyGraphValidator();
        $base = static fn (array $deps): array => [
            'factories' => ['a' => 'fa', 'b' => 'fb', 'c' => 'fc'],
            'aliases' => [],
            'deps' => array_merge(['a' => []], $deps),
            'modules' => ['a' => 'M1', 'b' => 'M2', 'c' => 'M2'],
        ];
        $one = $base(['a' => ['b']]);
        $g->validate($one['factories'], $one['aliases'], $one['deps'], $one['modules'], [], 1);
        // Exactly at the limit is legitimate; the second distinct edge breaks it.
        $two = $base(['a' => ['b', 'c']]);

        try {
            $g->validate($two['factories'], $two['aliases'], $two['deps'], $two['modules'], [], 1);
            self::fail('cross-module budget must be enforced.');
        } catch (ModuleDependencyViolationException $e) {
            self::assertStringContainsString("Module 'M1' exceeds cross-module reference limit (1) towards 'M2'", $e->getMessage());
        }
        // Duplicated edges to the same target count once (edge dedup).
        $dup = $base(['a' => ['b', 'b']]);
        $g->validate($dup['factories'], $dup['aliases'], $dup['deps'], $dup['modules'], [], 1);
        // Budget 0 disables the check entirely.
        $g->validate($two['factories'], $two['aliases'], $two['deps'], $two['modules'], [], 0);
    }

    public function testServiceCycleIsDetectedWithPreciseStackChain(): void
    {
        $g = new DependencyGraphValidator();

        try {
            $g->validate(
                ['a' => 'fa', 'b' => 'fb', 'c' => 'fc'],
                [],
                ['a' => ['b'], 'b' => ['c'], 'c' => ['a']],
                ['a' => 'M1', 'b' => 'M1', 'c' => 'M1'],
            );
            self::fail('service cycle must be rejected.');
        } catch (ServiceCircularDependencyException $e) {
            self::assertStringContainsString('a -> b -> c -> a', $e->getMessage());
        }
    }

    public function testDiamondDependenciesDoNotFalselyReportCycles(): void
    {
        $g = new DependencyGraphValidator();
        $g->validate(
            ['a' => 'fa', 'b' => 'fb', 'c' => 'fc', 'd' => 'fd'],
            [],
            ['a' => ['b', 'c'], 'b' => ['d'], 'c' => ['d'], 'd' => []],
            ['a' => 'M1', 'b' => 'M1', 'c' => 'M1', 'd' => 'M1'],
        );
        self::expectNotToPerformAssertions();
    }

    public function testValidateToleratesMissingMetadataMaps(): void
    {
        $g = new DependencyGraphValidator();
        // No depsOf entry, no moduleOf entry, no lifetimeOf entry at all.
        $g->validate(['a' => 'fa'], [], [], []);
        self::expectNotToPerformAssertions();
    }

    // --------------------------------------------------------------- #
    // FieldRules — numeric boundaries, multibyte, strictness.          #
    // --------------------------------------------------------------- #

    public function testMinLengthBoundaryAndNegativeGuard(): void
    {
        $rules = new FieldRules('f');

        try {
            $rules->minLength(-1);
            self::fail('negative minLength must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('minLength must be >= 0.', $e->getMessage());
        }
        self::assertSame([], new FieldRules('f')->minLength(0)->validate(''));
        self::assertSame([], new FieldRules('f')->minLength(2)->validate('ab'));
        self::assertCount(1, new FieldRules('f')->minLength(2)->validate('a'));
        // Multibyte: 'éé' is 2 characters but 4 bytes — mb_strlen must win.
        self::assertSame([], new FieldRules('f')->minLength(2)->validate('éé'));
        self::assertCount(1, new FieldRules('f')->minLength(3)->validate('éé'));
    }

    public function testMaxLengthBoundary(): void
    {
        $rules = new FieldRules('f');

        try {
            $rules->maxLength(0);
            self::fail('maxLength below 1 must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('maxLength must be >= 1.', $e->getMessage());
        }
        self::assertSame([], new FieldRules('f')->maxLength(1)->validate('a'));
        self::assertCount(1, new FieldRules('f')->maxLength(1)->validate('ab'));
        self::assertSame([], new FieldRules('f')->maxLength(2)->validate('éé'));
        self::assertCount(1, new FieldRules('f')->maxLength(1)->validate('éé'));
        self::assertSame([], new FieldRules('f')->maxLength(1)->validate('é'));
    }

    public function testTypeIntDigitCeiling(): void
    {
        $rules = new FieldRules('f')->typeInt();
        foreach ([5, '5', '-5', '0', '-0', '123456789012345678'] as $good) {
            self::assertSame([], $rules->validate($good), "typeInt must accept '{$good}'");
        }
        // null/''/[] are skipped by the default nullable/empty policy of
        // non-required rules; everything else below must genuinely fail.
        foreach (['1234567890123456789', '12.5', '1e5', ' 12', '+12', '١٢', true] as $bad) {
            self::assertCount(1, $rules->validate($bad), "typeInt must reject '{$bad}'");
        }
    }

    public function testTypeNumericAcceptsIntsFloatsAndNumericStrings(): void
    {
        $rules = new FieldRules('f')->typeNumeric();
        foreach ([5, 1.5, '1.5', '1e5', '0', '-3'] as $good) {
            self::assertSame([], $rules->validate($good), "typeNumeric must accept '{$good}'");
        }
        foreach (['abc', false] as $bad) {
            self::assertCount(1, $rules->validate($bad), "typeNumeric must reject '{$bad}'");
        }
    }

    public function testMinMaxFloatBoundariesInclusive(): void
    {
        self::assertSame([], new FieldRules('f')->min(1.5)->validate(1.5));
        self::assertCount(1, new FieldRules('f')->min(1.5)->validate(1.49));
        self::assertSame([], new FieldRules('f')->min(1.5)->validate('1.5'));
        self::assertSame([], new FieldRules('f')->max(10)->validate(10));
        self::assertCount(1, new FieldRules('f')->max(10)->validate(10.01));
        self::assertCount(1, new FieldRules('f')->min(0)->validate(false), 'booleans are not numeric');
        self::assertCount(1, new FieldRules('f')->max(0)->validate('abc'));
    }

    public function testInListIsStrictAboutTypesAndContents(): void
    {
        $rules = new FieldRules('f')->in(['a', 'b']);
        self::assertSame([], $rules->validate('a'));
        self::assertCount(1, $rules->validate('c'));
        self::assertCount(1, $rules->validate(0));
        self::assertCount(1, $rules->validate(['a']));
        $intList = new FieldRules('f')->in([1, 2]);
        self::assertSame([], $intList->validate(1));
        self::assertCount(1, $intList->validate('1'), 'in() must be strictly typed');
        foreach ([[true], [1.5], [null], [[]]] as $bad) {
            try {
                // @phpstan-ignore argument.type (the invalid allowed values ARE the scenario)
                new FieldRules('f')->in($bad);
                self::fail('non string/int allowed values must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('in() accepts only string/int values.', $e->getMessage());
            }
        }
    }

    public function testPatternEnforcesRegexAndSubjectBounds(): void
    {
        $rules = new FieldRules('f');

        try {
            $rules->pattern('');
            self::fail('empty pattern must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('pattern() regex length must be 1..2048.', $e->getMessage());
        }
        $rules->pattern('/^' . str_repeat('a', 2044) . '$/'); // 2048 bytes total: at the limit

        try {
            $rules->pattern('/^' . str_repeat('a', 2045) . '$/'); // 2049 bytes total
            self::fail('pattern above 2048 bytes must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('pattern() regex length must be 1..2048.', $e->getMessage());
        }
        $subject = new FieldRules('f')->pattern('/^a+$/');
        self::assertSame([], $subject->validate(str_repeat('a', 4096)));
        self::assertCount(1, $subject->validate(str_repeat('a', 4097)), 'subject above 4096 bytes must fail fast');
        self::assertCount(1, $subject->validate(42), 'non-string subjects never reach preg_match');
    }

    public function testEmailAndUuidRules(): void
    {
        $email = new FieldRules('f')->email();
        self::assertSame([], $email->validate('a@b.co'));
        self::assertCount(1, $email->validate('not-an-email'));
        self::assertCount(1, $email->validate(5));
        $uuid = new FieldRules('f')->uuid();
        self::assertSame([], $uuid->validate('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        self::assertSame([], $uuid->validate('3F2504E0-4F89-11D3-9A0C-0305E82C3301'));
        self::assertCount(1, $uuid->validate('3f2504e0-4f89-11d3-9a0c-0305e82c330'));
        self::assertCount(1, $uuid->validate("3f2504e0-4f89-11d3-9a0c-0305e82c3301\n"));
        self::assertCount(1, $uuid->validate(42));
    }

    public function testRequiredTreatsNullEmptyStringAndEmptyArrayAsMissing(): void
    {
        $rules = new FieldRules('f')->required();
        foreach ([null, '', []] as $missing) {
            self::assertCount(1, $rules->validate($missing), 'required must reject missing values');
        }
        foreach (['0', 0, false, 'x'] as $present) {
            self::assertSame([], $rules->validate($present), 'required must accept present values');
        }
    }

    public function testNullableIsRetroactiveOverAllRegisteredRules(): void
    {
        $rules = new FieldRules('f')->required()->typeString()->minLength(3)->nullable();
        self::assertSame([], $rules->validate(null), 'nullable() must lift every earlier rule');
        self::assertCount(2, $rules->validate(5), 'required passes for 5; type and length both fail');
        $errors = $rules->validate(5);
        self::assertSame(['type', 'min_length'], [$errors[0]->rule, $errors[1]->rule]);
        // New rules registered afterwards keep skipping nulls by default.
        $more = new FieldRules('f')->typeString()->maxLength(2)->nullable();
        $more->maxLength(1);
        self::assertSame([], $more->validate(null));
    }

    public function testSkipEmptyAppliesToEmptyStringAndEmptyArrayOnly(): void
    {
        $rules = new FieldRules('f')->typeString();
        self::assertSame([], $rules->validate(''));
        self::assertSame([], $rules->validate([]));
        self::assertSame([], $rules->validate(null));
        self::assertCount(1, $rules->validate(0), '0 is neither null nor empty');
        self::assertCount(1, $rules->validate(false), 'false is neither null nor empty');
    }

    public function testAllFailingRulesAreCollectedInOrder(): void
    {
        $rules = new FieldRules('f')->typeString()->minLength(5);
        $errors = $rules->validate(123);
        self::assertCount(2, $errors, 'later rules see the original value, both may fail');
        self::assertSame('type', $errors[0]->rule);
        self::assertSame('min_length', $errors[1]->rule);
        self::assertSame("Field 'f' must be a string.", $errors[0]->message);
    }

    public function testCustomRuleUsesSuppliedMessage(): void
    {
        $rules = new FieldRules('f')->custom(static fn (mixed $v): bool => $v === 'ok', 'must be ok.');
        self::assertSame([], $rules->validate('ok'));
        $errors = $rules->validate('nope');
        self::assertCount(1, $errors);
        self::assertSame("Field 'f' must be ok.", $errors[0]->message);
        self::assertSame('custom', $errors[0]->rule);
    }

    // --------------------------------------------------------------- #
    // TrustedHostValidator — normalization & bracket stripping.        #
    // --------------------------------------------------------------- #

    public function testEmptyHostAndEmptyAllowListAreAlwaysAccepted(): void
    {
        new TrustedHostValidator(['good.example'])->assertTrusted('');
        new TrustedHostValidator()->assertTrusted('anything.example');
        self::expectNotToPerformAssertions();
    }

    public function testHostNormalizationTrimsLowersAndStripsBrackets(): void
    {
        $v = new TrustedHostValidator(['  Example.COM ']);
        $v->assertTrusted('example.com');
        $v->assertTrusted('  EXAMPLE.com');
        $v6 = new TrustedHostValidator(['::1']);
        $v6->assertTrusted('[::1]');
        $v6->assertTrusted('::1');

        try {
            $v6->assertTrusted('[::2]');
            self::fail('unbracketed comparison must not let other IPv6 through.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Untrusted host: [::2].', $e->getMessage());
        }
    }

    public function testSingleCharacterHostsAreNotBracketStripped(): void
    {
        $v = new TrustedHostValidator(['[']);
        $v->assertTrusted('[');
        self::expectNotToPerformAssertions();
    }

    // --------------------------------------------------------------- #
    // Validator — field budget, order, aggregation helpers.            #
    // --------------------------------------------------------------- #

    public function testFieldNameBoundaries(): void
    {
        $v = new Validator();
        foreach (['', str_repeat('n', 129)] as $bad) {
            try {
                $v->field($bad);
                self::fail('out-of-bounds field name must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Field name must be 1..128 bytes.', $e->getMessage());
            }
        }
        self::assertSame('a', $v->field('a')->field);
    }

    public function testFieldBudgetIsEnforcedAt128(): void
    {
        $v = new Validator();
        for ($i = 0; $i < 128; ++$i) {
            $v->field('f' . $i);
        }

        try {
            $v->field('overflow');
            self::fail('field budget must be enforced.');
        } catch (\OverflowException $e) {
            self::assertStringContainsString('Validator field budget exceeded (128).', $e->getMessage());
        }
        // Re-declaring an existing field never hits the budget.
        $v->field('f0');
        self::assertTrue($v->hasField('f0'));
        self::assertSame('f0', $v->fieldNames()[0]);
    }

    public function testValidatePreservesUnknownKeysAndData(): void
    {
        $v = new Validator();
        $v->field('name')->typeString();
        $result = $v->validate(['name' => 'ok', 'extra' => 1]);
        self::assertTrue($result->ok());
        self::assertSame(['name' => 'ok', 'extra' => 1], $result->data);

        $bad = $v->validate(['name' => 7, 'extra' => 1]);
        self::assertFalse($bad->ok());
        self::assertCount(1, $bad->errors);
        self::assertSame(["Field 'name' must be a string."], $bad->messages());
        self::assertSame([], $bad->errorsFor('extra'));
        self::assertSame("Field 'name' must be a string.", (string) $bad->firstMessage());
        self::assertNull(new ValidationResult([], [])->firstMessage());
    }

    // --------------------------------------------------------------- #
    // ValidationTranslator — placeholder interpolation & fallbacks.    #
    // --------------------------------------------------------------- #

    public function testTranslatorRewritesKnownRulesAndKeepsUnknownOnes(): void
    {
        $catalog = MessageCatalog::defaultEnglish()
            ->with('id', ['required' => '{{label}} wajib diisi.'])
        ;
        $t = new ValidationTranslator($catalog);

        $source = new ValidationResult([
            new ValidationError('email', "Field 'email' is required.", 'required'),
            new ValidationError('age', "Field 'age' must be a string.", 'unknown_rule'),
        ], ['email' => '']);
        $translated = $t->translate($source, 'id_ID', ['email' => 'Surel']);
        self::assertSame('Surel wajib diisi.', $translated->errors[0]->message);
        self::assertSame("Field 'age' must be a string.", $translated->errors[1]->message, 'unknown rules keep the original message');
        self::assertSame(['email' => ''], $translated->data);

        $wildcard = $t->translate($source, 'fr');
        self::assertSame('email is required.', $wildcard->errors[0]->message, 'wildcard template catches unknown locales');
        self::assertSame("Field 'age' must be a string.", $wildcard->errors[1]->message, 'unknown rules keep the original message');
    }

    public function testTranslatorReturnsSuccessfulResultsUntouched(): void
    {
        $t = new ValidationTranslator(MessageCatalog::defaultEnglish());
        $ok = new ValidationResult([], ['a' => 1]);
        self::assertSame($ok, $t->translate($ok, 'id'));
    }

    // --------------------------------------------------------------- #
    // Small validators — three-point boundaries.                       #
    // --------------------------------------------------------------- #

    public function testHttpStatusBoundaries(): void
    {
        $v = new HttpStatusValidator();
        $v->assert(100);
        $v->assert(599);
        foreach ([99, 600, 0, -1] as $bad) {
            try {
                $v->assert($bad);
                self::fail("status {$bad} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("Invalid HTTP status code: {$bad}.", $e->getMessage());
            }
        }
    }

    public function testHttpMethodTokenRules(): void
    {
        HttpMethodValidator::assert('GET');
        HttpMethodValidator::assert("custom!#$%&'*+.^_`|~-METHOD_42");
        foreach (['', 'GET /', 'GET()', 'GÉT', 'GET,x'] as $bad) {
            try {
                HttpMethodValidator::assert($bad);
                self::fail("method '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("Invalid HTTP method '{$bad}'.", $e->getMessage());
            }
        }
    }

    public function testPortRangeBoundaries(): void
    {
        $v = new PortRangeValidator();
        $v->assert(null);
        $v->assert(1);
        $v->assert(65535);
        foreach ([0, 65536, -1] as $bad) {
            try {
                $v->assert($bad);
                self::fail("port {$bad} must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("Invalid port: {$bad}.", $e->getMessage());
            }
        }
    }

    public function testOpaqueIdBoundaries(): void
    {
        try {
            Identifier::assertOpaqueId(str_repeat('a', 7));
            self::fail('opaque id below 8 bytes must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid ID.', $e->getMessage());
        }
        Identifier::assertOpaqueId(str_repeat('a', 8));
        Identifier::assertOpaqueId(str_repeat('a', 128));

        try {
            Identifier::assertOpaqueId(str_repeat('a', 129));
            self::fail('opaque id above 128 bytes must be rejected.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        try {
            Identifier::assertOpaqueId('bad id', 'job key');
            self::fail('opaque id charset must be enforced.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid job key.', $e->getMessage());
        }
        self::assertTrue(Identifier::isValidOpaqueId('a.b:c-1_'));
        self::assertFalse(Identifier::isValidOpaqueId('short'));
    }

    public function testTraceParentShapes(): void
    {
        Identifier::assertTraceParent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        Identifier::assertTraceParent('FF-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-FF-key=value');
        $rejected = 0;
        foreach ([
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7',
            '0-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            '00-gf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-has space',
        ] as $bad) {
            try {
                Identifier::assertTraceParent($bad);
                self::fail("traceparent '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(4, $rejected, 'all malformed traceparents must be rejected');
    }

    public function testModuleNameAndMessageTypeShapes(): void
    {
        Identifier::assertModuleName('my_module');
        Identifier::assertModuleName('mod.1-x');
        foreach (['', '9mod', 'mod name'] as $bad) {
            try {
                Identifier::assertModuleName($bad);
                self::fail("module name '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("Invalid module name '{$bad}'.", $e->getMessage());
            }
        }
        Identifier::assertMessageType(str_repeat('a', 255));
        foreach ([str_repeat('a', 256), 'sp ace', ''] as $bad) {
            try {
                Identifier::assertMessageType($bad);
                self::fail('message type above bounds must be rejected.');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
    }

    public function testValidationErrorRejectsEmptyFieldAndSerializes(): void
    {
        try {
            new ValidationError('', 'msg');
            self::fail('empty field must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Validation error field must not be empty.', $e->getMessage());
        }
        $error = new ValidationError('f', 'm', 'r');
        self::assertSame(['field' => 'f', 'message' => 'm', 'rule' => 'r'], $error->toArray());
        $defaulted = new ValidationError('f', 'm');
        self::assertSame('', $defaulted->rule);
    }
}
