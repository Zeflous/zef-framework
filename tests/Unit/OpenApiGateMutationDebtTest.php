<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.33.0 — OpenAPI runtime gate mutation-debt killers.
 *
 * Round-1 campaign: 985 mutants, 151 escaped, 34 not-covered (MSI 81.22).
 * These tests pin the escaping behaviours exactly: boot failure message
 * composition, candidate ordering (sorted-first, first-valid-wins,
 * first-failure-reported), Allow ordering, template-segment anchoring,
 * splitPath normalisation, boundary integers (2048, 64, 512, item/property
 * counts), multibyte lengths, enum strictness, garbage tolerance that is
 * reachable through hand-written documents, scheme-evidence arms, response
 * key precedence, and the scoped error-handler restoration.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\OpenApiGateException;
use Zef\Framework\OpenApi\OpenApiGateOptions;
use Zef\Framework\OpenApi\OpenApiGateRequest;
use Zef\Framework\OpenApi\OpenApiRequestGate;
use Zef\Framework\OpenApi\OpenApiSchemaChecker;

/**
 * @internal
 */
final class OpenApiGateMutationDebtTest extends TestCase
{
    public function testBootFailureMessageJoinsEveryErrorInValidatorOrder(): void
    {
        try {
            OpenApiRequestGate::fromSpec(['openapi' => 'nope'], new OpenApiGateOptions());
            self::fail('OpenApiGateException expected');
        } catch (OpenApiGateException $exception) {
            self::assertSame(
                'The OpenAPI document is not enforceable by the runtime gate: '
                . 'Field "openapi" must be a semver string like "3.1.0". '
                . 'Field "info" must be an object. '
                . 'Field "info.title" must be a non-empty string. '
                . 'Field "info.version" must be a non-empty string. '
                . 'Field "paths" must be an object.',
                $exception->getMessage(),
            );
        }
    }

    public function testFirstSortedTemplateWinningIsValidatesWins(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->twoIntTemplatesSpec(), new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/users/5'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        // Insertion order is {b} first; the sorted order puts {a} first and
        // the sorted winner must be reported.
        self::assertSame('a', $verdict->operation['operationId']);
    }

    public function testBothTemplatesValidatingStillPicksTheSortedFirst(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->mixedTemplatesSpec(), new OpenApiGateOptions());

        // Both templates validate '5' (integer and string); the first in
        // sorted order wins — the loop must break at the first success.
        $verdict = $gate->evaluate($this->request('GET', '/items/5'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('a', $verdict->operation['operationId']);
    }

    public function testFailingFirstTemplateFallsThroughToTheNext(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->mixedTemplatesSpec(), new OpenApiGateOptions());

        // 'xyz' fails the integer template and validates the string one.
        $verdict = $gate->evaluate($this->request('GET', '/items/xyz'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('b', $verdict->operation['operationId']);
    }

    public function testAllTemplatesFailingReportsTheFirstIssues(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->twoIntTemplatesSpec(), new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/users/xyz'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        // The coalescing assignment keeps the FIRST failing candidate's
        // issues (parameter name 'a'), never the later ones.
        self::assertSame([
            ['in' => 'path', 'name' => 'a', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testAllowHeaderIsSortedRegardlessOfDeclarationOrder(): void
    {
        $spec = $this->baseSpec([
            '/x' => [
                'post' => $this->operation('postX'),
                'get' => $this->operation('getX'),
            ],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('DELETE', '/x'));
        self::assertFalse($verdict->admitted);
        self::assertSame(405, $verdict->status);
        // Declaration order is post, get — the reported set is sorted with
        // HEAD implied by GET.
        self::assertSame(['Allow' => 'GET, HEAD, POST'], $verdict->headers);
        self::assertSame(['GET', 'HEAD', 'POST'], $verdict->extensions['allowed']);
    }

    public function testPostOnlyTemplateDoesNotImplyHead(): void
    {
        $spec = $this->baseSpec(['/x' => ['post' => $this->operation('postX')]]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('DELETE', '/x'));
        self::assertSame(405, $verdict->status);
        self::assertSame(['POST'], $verdict->extensions['allowed']);
        self::assertSame(['Allow' => 'POST'], $verdict->headers);
    }

    public function testPartialPlaceholderSegmentsAreStaticLiterals(): void
    {
        $dollarSpec = $this->baseSpec(['/users/{x}zz' => ['get' => $this->operation('dollar')]]);
        $gate = OpenApiRequestGate::fromSpec($dollarSpec, new OpenApiGateOptions());
        // '{x}zz' is a static literal: only the exact segment matches.
        $verdict = $gate->evaluate($this->request('GET', '/users/7zz'));
        self::assertTrue($verdict->admitted);
        self::assertNull($verdict->operation);

        $caretSpec = $this->baseSpec(['/users/zz{x}' => ['get' => $this->operation('caret')]]);
        $gate = OpenApiRequestGate::fromSpec($caretSpec, new OpenApiGateOptions());
        $verdict = $gate->evaluate($this->request('GET', '/users/zz7'));
        self::assertTrue($verdict->admitted);
        self::assertNull($verdict->operation);
    }

    public function testDuplicateSlashesCollapseOnBothSides(): void
    {
        // Request side: '/a//b' normalises to the same segments as '/a/b'.
        $spec = $this->baseSpec(['/a/b' => ['get' => $this->operation('ab')]]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());
        $verdict = $gate->evaluate($this->request('GET', '/a//b'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('ab', $verdict->operation['operationId']);

        // Template side: a '//'-containing template key re-indexes to the
        // same segment list, so it still matches the single-slash request.
        $templateSpec = $this->baseSpec(['/a//b' => ['get' => $this->operation('ab2')]]);
        $gate = OpenApiRequestGate::fromSpec($templateSpec, new OpenApiGateOptions());
        $verdict = $gate->evaluate($this->request('GET', '/a/b'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('ab2', $verdict->operation['operationId']);
    }

    public function testNonPathParamSkippedEvenWhenItsNameCollidesWithAPlaceholder(): void
    {
        // The query parameter named 'id' shares its name with the path
        // placeholder; the path branch must validate the placeholder exactly
        // once (against the captured value) and never re-validate the
        // captured value against the query schema.
        $spec = $this->baseSpec([
            '/users/{id}' => ['get' => $this->operation('getUser', [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ['name' => 'id', 'in' => 'query', 'required' => false, 'schema' => ['enum' => ['x']]],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/users/7'));
        self::assertTrue($verdict->admitted);
    }

    public function testMultiSchemeRequirementDetailListsEveryScheme(): void
    {
        $spec = $this->lockedSpec([['first' => [], 'second' => []]], [
            'first' => ['type' => 'http', 'scheme' => 'bearer'],
            'second' => ['type' => 'http', 'scheme' => 'basic'],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/locked'));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
        self::assertSame('The security requirement (first, second) is not satisfied.', $verdict->detail);
        self::assertSame([
            ['in' => 'security', 'name' => 'first', 'pointer' => '', 'message' => "security scheme 'first' is not satisfied"],
            ['in' => 'security', 'name' => 'second', 'pointer' => '', 'message' => "security scheme 'second' is not satisfied"],
        ], $verdict->issues);
    }

    public function testGarbageTopLevelSecurityEntryIsIgnoredAndFailsClosed(): void
    {
        // A non-array requirement in the TOP-LEVEL security list is not
        // boot-validated (the validator walks schemes, not the security
        // list), so the engine must skip it and then reject anonymously.
        $spec = $this->baseSpec(['/open' => ['get' => $this->operation('open')]]);
        $spec['security'] = ['garbage-requirement'];
        $spec['components'] = ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]];
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/open'));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
    }

    public function testDigestAndExoticHttpSchemesHaveDistinctEvidence(): void
    {
        $digest = $this->lockedSpec([['digest' => []]], ['digest' => ['type' => 'http', 'scheme' => 'Digest']]);
        $gate = OpenApiRequestGate::fromSpec($digest, new OpenApiGateOptions());

        self::assertFalse($gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Bearer tok']))->admitted);
        $verdict = $gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Digest abc']));
        self::assertTrue($verdict->admitted);

        // Exotic scheme names accept any non-empty Authorization evidence,
        // and the scheme name is trimmed and case-folded.
        $exotic = $this->lockedSpec([['hoba' => []]], ['hoba' => ['type' => 'http', 'scheme' => ' HOBA ']]);
        $gate = OpenApiRequestGate::fromSpec($exotic, new OpenApiGateOptions());
        $verdict = $gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Challenge abc']));
        self::assertTrue($verdict->admitted);
    }

    public function testEmptyQueryOrCookieOrHeaderValuesAreNotApiKeyEvidence(): void
    {
        foreach (['query', 'cookie'] as $location) {
            $spec = $this->lockedSpec([['key' => []]], ['key' => ['type' => 'apiKey', 'in' => $location, 'name' => 'X-Key']]);
            $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

            $empty = $this->request('GET', '/locked', ...$this->carrier($location, ''));
            self::assertFalse($gate->evaluate($empty)->admitted, "empty {$location} value must not be evidence");

            $present = $this->request('GET', '/locked', ...$this->carrier($location, 'v'));
            self::assertTrue($gate->evaluate($present)->admitted, "non-empty {$location} value is evidence");
        }

        $spec = $this->lockedSpec([['key' => []]], ['key' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key']]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());
        self::assertFalse($gate->evaluate($this->request('GET', '/locked', headers: ['x-key' => '']))->admitted);
    }

    public function testBodyJsonDepthBoundaryIsExact(): void
    {
        $spec = $this->baseSpec([
            '/b' => ['post' => $this->operation('postB', [], ['requestBody' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'integer']]],
            ]])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        // 511 levels decode; 512 is beyond the parsing depth.
        $deep511 = str_repeat('[', 511) . str_repeat(']', 511);
        $verdict = $gate->evaluate($this->request('POST', '/b', contentType: 'application/json', body: $deep511));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame('expected integer, got array', $verdict->issues[0]['message'] ?? '');

        $deep512 = str_repeat('[', 512) . str_repeat(']', 512);
        $verdict = $gate->evaluate($this->request('POST', '/b', contentType: 'application/json', body: $deep512));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'body', 'name' => 'application/json', 'pointer' => '', 'message' => 'malformed JSON body'],
        ], $verdict->issues);
    }

    public function testResponseMediaTypeNormalisationIsWhitespaceAndCaseTolerant(): void
    {
        $responses = [200 => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        self::assertSame([], $gate->checkResponse($responses, 200, ' Application/JSON ; charset=utf-8 ', '42'));
        $issues = $gate->checkResponse($responses, 200, '  TEXT/PLAIN  ', '42');
        self::assertSame("response media type 'text/plain' is not documented", $issues[0]['message'] ?? '');
    }

    public function testResponseKeyPrecedenceIsExactThenDefaultThenRange(): void
    {
        $both = [
            'default' => ['description' => 'fallback', 'content' => ['text/plain' => ['schema' => ['type' => 'integer']]]],
            '5XX' => ['description' => 'range'],
        ];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        // 'default' wins over the range entry: its (non-JSON) media type is
        // the one consulted, so no schema check happens.
        self::assertSame([], $gate->checkResponse($both, 503, 'text/plain', 'anything'));
    }

    public function testResponseBranchesForEmptyAndPresentBodies(): void
    {
        $noContent = [204 => ['description' => 'no content']];
        $withContent = [200 => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        self::assertSame([], $gate->checkResponse($noContent, 204, 'application/json', null));
        self::assertSame(
            'response body is present but no content is documented',
            $gate->checkResponse($noContent, 204, 'application/json', 'payload')[0]['message'] ?? '',
        );
        self::assertSame([], $gate->checkResponse($withContent, 200, 'application/json', '  '));
    }

    /**
     * --- OpenApiSchemaChecker debt ---.
     */
    public function testCheckerPatternPolicyBoundaryIsExact(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        // A pattern of exactly 2048 characters is inside the policy and
        // must be compiled and honoured (the value matches it).
        $exactly2048 = ['type' => 'string', 'pattern' => str_repeat('a', 2048)];
        self::assertSame([], $checker->check(str_repeat('a', 2048), $exactly2048, false));
        self::assertSame(
            'schema pattern exceeds the 2048-character policy',
            $checker->check('a', ['type' => 'string', 'pattern' => str_repeat('a', 2049)], false)[0]['message'] ?? '',
        );

        // Empty or non-string patterns impose no constraint at all.
        self::assertSame([], $checker->check('x', ['type' => 'string', 'pattern' => ''], false));
        self::assertSame([], $checker->check('x', ['type' => 'string', 'pattern' => 42], false));
    }

    public function testCheckerPatternOnNonStringValueIsSkipped(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check(5, ['pattern' => '^[a-z]+$'], false));
    }

    public function testCheckerDepthBoundaryIsExact(): void
    {
        $schemas = ['Node' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']]];
        $checker = new OpenApiSchemaChecker($schemas);

        $nest = static function (int $levels): array {
            $value = [];
            for ($i = 0; $i < $levels; ++$i) {
                $value = [$value];
            }

            return $value;
        };

        self::assertSame([], $checker->check($nest(31), ['$ref' => '#/components/schemas/Node'], false));
        $issues = $checker->check($nest(33), ['$ref' => '#/components/schemas/Node'], false);
        self::assertSame('schema nesting exceeds 64 levels', $issues[0]['message'] ?? '');
    }

    public function testCheckerAccumulatesEveryStringIssueInOrder(): void
    {
        $schema = ['type' => 'string', 'minLength' => 5, 'maxLength' => 3, 'pattern' => '^[z]+$', 'format' => 'email'];
        $issues = new OpenApiSchemaChecker([])->check('abcd', $schema, false);
        self::assertSame([
            ['pointer' => '', 'message' => 'string is shorter than minLength 5'],
            ['pointer' => '', 'message' => 'string is longer than maxLength 3'],
            ['pointer' => '', 'message' => 'value does not match the required pattern'],
            ['pointer' => '', 'message' => 'value is not a valid email'],
        ], $issues);
    }

    public function testCheckerMultibyteBoundaries(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        // mb_strlen('éé') = 2: within maxLength 3 even though strlen = 4.
        self::assertSame([], $checker->check('éé', ['type' => 'string', 'maxLength' => 3], false));
        // mb_strlen('é') = 1: below minLength 2.
        $issues = $checker->check('é', ['type' => 'string', 'minLength' => 2], false);
        self::assertSame('string is shorter than minLength 2', $issues[0]['message'] ?? '');
        // Exact boundaries are inclusive on both sides.
        self::assertSame([], $checker->check('ab', ['type' => 'string', 'minLength' => 2], false));
        self::assertSame([], $checker->check('abc', ['type' => 'string', 'maxLength' => 3], false));
    }

    public function testCheckerItemAndPropertyCountBoundariesAreInclusive(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check(['a', 'b'], ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2], false));
        self::assertSame([], $checker->check(['a', 'b', 'c'], ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3], false));
        self::assertSame([], $checker->check(['a' => 1, 'b' => 2], ['type' => 'object', 'minProperties' => 2], false));
        self::assertSame([], $checker->check(['a' => 1], ['type' => 'object', 'maxProperties' => 1], false));
    }

    public function testCheckerUniqueItemsDisabledAndComplexValues(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check(['a', 'a'], ['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => false], false));
        $issues = $checker->check([[1], [1]], ['type' => 'array', 'items' => ['type' => 'integer'], 'uniqueItems' => true], false);
        self::assertSame('array items are not unique', $issues[0]['message'] ?? '');
    }

    public function testCheckerEnumStrictnessAndGarbageTolerance(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        // Strict comparison: the string '42' is not the integer 42.
        $issues = $checker->check('42', ['enum' => [42]], false);
        self::assertSame('value is not one of the enumerated values', $issues[0]['message'] ?? '');
        // Non-array enum entries impose nothing.
        self::assertSame([], $checker->check('42', ['enum' => 'garbage'], false));
    }

    public function testCheckerNumericConstraintsSkipNonNumbers(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check('5', ['type' => 'string', 'minimum' => 1], false));
        self::assertSame([], $checker->check('5', ['type' => 'string', 'maximum' => 1], false));
    }

    public function testCheckerRequiredGarbageTolerance(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check(['a' => 1], ['type' => 'object', 'required' => 'garbage'], false));
        self::assertSame([], $checker->check(['a' => 1], ['type' => 'object', 'required' => [42, 'a']], false));
    }

    public function testCheckerPropertiesAndAdditionalGarbageTolerance(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        // Garbage property schemas impose nothing (presence still allowed).
        self::assertSame([], $checker->check(['a' => 1], ['type' => 'object', 'properties' => ['a' => 'garbage']], false));
        // Explicit true / non-schema garbage additionalProperties allow extras.
        self::assertSame([], $checker->check(['a' => 1], ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => true], false));
        self::assertSame([], $checker->check(['a' => 1, 'b' => 2], ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => 'garbage'], false));
    }

    public function testCheckerNonArraySchemaImposesNothing(): void
    {
        self::assertSame([], new OpenApiSchemaChecker([])->check('x', 'garbage', false));
    }

    public function testCheckerValueLabelsCoverEveryTypeArm(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $expect = static fn (mixed $value, string $label): string => "expected string, got {$label}";
        self::assertSame($expect(1, 'integer'), $checker->check(1, ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect(1.5, 'number'), $checker->check(1.5, ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect(true, 'boolean'), $checker->check(true, ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect(['a'], 'array'), $checker->check(['a'], ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect(['a' => 1], 'object'), $checker->check(['a' => 1], ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect([], 'empty array'), $checker->check([], ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect(null, 'null'), $checker->check(null, ['type' => 'string'], false)[0]['message'] ?? '');
        self::assertSame($expect(new \stdClass(), 'unknown'), $checker->check(new \stdClass(), ['type' => 'string'], false)[0]['message'] ?? '');
    }

    public function testCheckerUuidAndDateTimeAnchorsAreExact(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        self::assertSame('value is not a valid uuid', $checker->check('xx3e4567-e89b-12d3-a456-426614174000', $uuid, false)[0]['message'] ?? '');
        self::assertSame('value is not a valid uuid', $checker->check('123e4567-e89b-12d3-a456-426614174000xx', $uuid, false)[0]['message'] ?? '');

        $dateTime = ['type' => 'string', 'format' => 'date-time'];
        self::assertSame('value is not a valid date-time', $checker->check('x2026-09-30T10:00:00Z', $dateTime, false)[0]['message'] ?? '');
        self::assertSame('value is not a valid date-time', $checker->check('2026-09-30T10:00:00Zx', $dateTime, false)[0]['message'] ?? '');
    }

    public function testCheckerRefAndSiblingIssuesAccumulateInOrder(): void
    {
        $schemas = ['User' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]];
        $checker = new OpenApiSchemaChecker($schemas);
        $issues = $checker->check([], ['$ref' => '#/components/schemas/User', 'minProperties' => 1], false);
        self::assertSame([
            ['pointer' => '', 'message' => "missing required property 'id'"],
            ['pointer' => '', 'message' => 'object has fewer than minProperties 1'],
        ], $issues);
    }

    /*
     * Round-2 killers: exact boundaries, dead-data-free refactored paths,
     * nested pointers, response-side strictness, scheme-evidence arms.
     */

    public function testCheckerObjectRecursionDepthBoundaryIsExact(): void
    {
        $schema = ['type' => 'object'];
        for ($i = 0; $i < 70; ++$i) {
            $schema = ['type' => 'object', 'properties' => ['c' => $schema]];
        }
        $checker = new OpenApiSchemaChecker([]);

        $nest = static function (int $levels): array {
            $value = [];
            for ($i = 0; $i < $levels; ++$i) {
                $value = ['c' => $value];
            }

            return $value;
        };

        // Exactly 64 levels is inside the bound; 65 trips it.
        self::assertSame([], $checker->check($nest(64), $schema, false));
        $issues = $checker->check($nest(65), $schema, false);
        self::assertSame('schema nesting exceeds 64 levels', $issues[0]['message'] ?? '');
    }

    public function testCheckerCompositionNestingIsDepthBounded(): void
    {
        $build = static function (int $levels): array {
            $schema = ['type' => 'string'];
            for ($i = 0; $i < $levels; ++$i) {
                $schema = ['oneOf' => [$schema]];
            }

            return $schema;
        };
        $checker = new OpenApiSchemaChecker([]);

        // 63 levels: the innermost string branch matches, every outer
        // oneOf sees exactly one satisfied branch — no issues at all.
        self::assertSame([], $checker->check('x', $build(63), false));

        // Beyond the depth bound the innermost check short-circuits with
        // the depth issue, which every enclosing oneOf reports as "no
        // branch matched".
        $issues = $checker->check('x', $build(70), false);
        self::assertSame('value matches none of the oneOf branches', $issues[0]['message'] ?? '');
    }

    public function testCheckerNestedArrayPointersAccumulate(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $schema = ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]];
        $issues = $checker->check([['x', 5]], $schema, false);
        self::assertSame([['pointer' => '/0/1', 'message' => 'expected string, got integer']], $issues);
    }

    public function testCheckerNestedAdditionalPropertiesPointersAccumulate(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
        ];
        $issues = $checker->check(['a' => ['x' => 5]], $schema, false);
        self::assertSame([['pointer' => '/a/x', 'message' => 'expected string, got integer']], $issues);
    }

    public function testCheckerMaximumBoundaryIsInclusive(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check(10, ['type' => 'integer', 'maximum' => 10], false));
        self::assertSame(
            'value is above the maximum 10',
            $checker->check(11, ['type' => 'integer', 'maximum' => 10], false)[0]['message'] ?? '',
        );
    }

    public function testCheckerTwoMissingRequiredPropertiesBothReported(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $issues = $checker->check([], ['type' => 'object', 'required' => ['a', 'b']], false);
        self::assertSame([
            ['pointer' => '', 'message' => "missing required property 'a'"],
            ['pointer' => '', 'message' => "missing required property 'b'"],
        ], $issues);
    }

    public function testCheckerAnyOfAndAllOfGarbageBranchesAreSkipped(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        $issues = $checker->check('x', ['anyOf' => ['garbage']], false);
        self::assertSame('value matches none of the anyOf branches', $issues[0]['message'] ?? '');
        self::assertSame([], $checker->check('x', ['allOf' => ['garbage']], false));
    }

    public function testTwoInvalidPathParametersAreBothReported(): void
    {
        $spec = $this->baseSpec([
            '/pair/{a}/{b}' => ['get' => $this->operation('pair', [
                ['name' => 'a', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                ['name' => 'b', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/pair/x/y'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'path', 'name' => 'a', 'pointer' => '', 'message' => 'expected integer, got string'],
            ['in' => 'path', 'name' => 'b', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testParameterOnlyRejectionUsesTheGenericDetail(): void
    {
        $spec = $this->baseSpec([
            '/q' => ['get' => $this->operation('q', [
                ['name' => 'must', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/q'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame('The request violates the documented API contract.', $verdict->detail);
    }

    public function testResponseBodiesAreNeverCoerced(): void
    {
        $responses = [200 => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        $issues = $gate->checkResponse($responses, 200, 'application/json', '"42"');
        self::assertSame([['in' => 'response', 'name' => 'application/json', 'pointer' => '', 'message' => 'expected integer, got string']], $issues);
    }

    public function testRangeKeysUseTheStatusCentury(): void
    {
        $responses = ['3XX' => ['description' => 'err', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        // 399 falls in the 3XX century, not the 4XX one.
        self::assertSame([], $gate->checkResponse($responses, 399, 'application/json', '42'));
    }

    public function testResponseDeepJsonIsRejectedAsMalformed(): void
    {
        $responses = [200 => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        $deep = str_repeat('[', 512) . str_repeat(']', 512);
        $issues = $gate->checkResponse($responses, 200, 'application/json', $deep);
        self::assertSame('response body is not valid JSON', $issues[0]['message'] ?? '');
    }

    public function testBearerEvidenceRequiresTheSpaceSeparator(): void
    {
        $spec = $this->lockedSpec([['bearer' => []]], ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        // 'BearerXYZ' is not a bearer credential: no scheme separator.
        $verdict = $gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'BearerXYZ']));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
    }

    public function testNamedHttpSchemeIsTrimmedAndCaseFolded(): void
    {
        $spec = $this->lockedSpec([['digest' => []]], ['digest' => ['type' => 'http', 'scheme' => ' Digest ']]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        // With the trim+fold intact the Digest arm applies and Bearer is rejected.
        self::assertFalse($gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Bearer tok']))->admitted);
        self::assertTrue($gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Digest abc']))->admitted);
    }

    public function testRequestContentTypeIsNormalisedWithCaseAndWhitespace(): void
    {
        $spec = $this->baseSpec([
            '/b' => ['post' => $this->operation('postB', [], [
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['type' => 'integer']]],
                ],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('POST', '/b', contentType: ' Application/JSON ; charset=utf-8 ', body: '42'));
        self::assertTrue($verdict->admitted);
    }

    /*
     * Round-4 killers: garbage-tolerance reaches (top-level security,
     * garbage requestBody, garbage composition branches), exact depth
     * accounting per recursion family, boolean-coercion value semantics,
     * empty-array type ambiguities, and the error-handler restoration.
     */

    public function testEmptyGrantedScopeIsFilteredOutOfTheScopeList(): void
    {
        $spec = $this->lockedSpec([['bearer' => ['']]], ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        // The granted '' scope is filtered out, so the required '' scope
        // counts as missing — the verdict is 403, never satisfied.
        $verdict = $gate->evaluate($this->request('GET', '/locked', attributes: [
            'zef.auth.identity' => 'u1',
            'zef.security.scopes' => [''],
        ]));
        self::assertFalse($verdict->admitted);
        self::assertSame(403, $verdict->status);
    }

    public function testResponseWhitespaceBodyWithoutDeclaredContentIsIgnored(): void
    {
        $noContent = [204 => ['description' => 'no content']];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        self::assertSame([], $gate->checkResponse($noContent, 204, 'application/json', '   '));
    }

    public function testResponseDeepButValidJsonStillParses(): void
    {
        $responses = [200 => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/x' => ['get' => $this->operation('x')]]), new OpenApiGateOptions());

        // 511 levels parse — the failure is the schema, not the depth.
        $deep511 = str_repeat('[', 511) . str_repeat(']', 511);
        $issues = $gate->checkResponse($responses, 200, 'application/json', $deep511);
        self::assertSame([['in' => 'response', 'name' => 'application/json', 'pointer' => '', 'message' => 'expected integer, got array']], $issues);
    }

    public function testRequestMethodWhitespaceIsTrimmed(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/ping' => ['get' => $this->operation('ping')]]), new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request(' GET ', '/ping'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
    }

    public function testNonPathParamBeforePathParamDoesNotAbortPathValidation(): void
    {
        // The query parameter is declared FIRST: the path loop must skip it
        // and still validate the path parameter that follows.
        $spec = $this->baseSpec([
            '/users/{id}' => ['get' => $this->operation('getUser', [
                ['name' => 'expand', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/users/abc'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'path', 'name' => 'id', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testCookieParametersAreValidated(): void
    {
        $spec = $this->baseSpec([
            '/c' => ['get' => $this->operation('c', [
                ['name' => 'session', 'in' => 'cookie', 'required' => true, 'schema' => ['type' => 'string', 'maxLength' => 4]],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $missing = $gate->evaluate($this->request('GET', '/c'));
        self::assertFalse($missing->admitted);
        self::assertSame("required cookie parameter 'session' is missing", $missing->issues[0]['message'] ?? '');

        $tooLong = $gate->evaluate($this->request('GET', '/c', cookies: ['session' => 'abcdef']));
        self::assertFalse($tooLong->admitted);
        self::assertSame('string is longer than maxLength 4', $tooLong->issues[0]['message'] ?? '');

        $fine = $gate->evaluate($this->request('GET', '/c', cookies: ['session' => 'abcd']));
        self::assertTrue($fine->admitted);
    }

    public function testTwoInvalidQueryParametersAreBothReported(): void
    {
        $spec = $this->baseSpec([
            '/q' => ['get' => $this->operation('q', [
                ['name' => 'a', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                ['name' => 'b', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/q', query: ['a' => 'x', 'b' => 'y']));
        self::assertSame([
            ['in' => 'query', 'name' => 'a', 'pointer' => '', 'message' => 'expected integer, got string'],
            ['in' => 'query', 'name' => 'b', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testGarbageRequestBodyHasNoContract(): void
    {
        // The validator does not check requestBody shape, so garbage is a
        // reachable input — and imposes no body contract at all.
        $spec = $this->baseSpec([
            '/b' => ['post' => ['operationId' => 'b', 'responses' => ['200' => ['description' => 'ok']], 'requestBody' => 'garbage']],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('POST', '/b', contentType: 'text/plain', body: 'anything'));
        self::assertTrue($verdict->admitted);
    }

    public function testEmptyRequestBodyContentMapHasNoContract(): void
    {
        $spec = $this->baseSpec([
            '/b' => ['post' => ['operationId' => 'b', 'responses' => ['200' => ['description' => 'ok']], 'requestBody' => ['required' => true, 'content' => []]]],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('POST', '/b', contentType: 'application/json', body: '{}'));
        self::assertTrue($verdict->admitted);
    }

    public function testGarbageTopLevelSecurityDoesNotAbortLaterRequirements(): void
    {
        $spec = $this->baseSpec(['/open' => ['get' => $this->operation('open')]]);
        $spec['security'] = ['garbage-requirement', ['bearer' => []]];
        $spec['components'] = ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]];
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/open', headers: ['authorization' => 'Bearer tok']));
        self::assertTrue($verdict->admitted);
    }

    public function testBasicSchemeRejectsBearerEvidence(): void
    {
        $spec = $this->lockedSpec([['basic' => []]], ['basic' => ['type' => 'http', 'scheme' => 'basic']]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Bearer xyz']));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
    }

    public function testCoercedBooleanValuesFeedEnumStrictly(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check('true', ['type' => 'boolean', 'enum' => [true]], true));
        self::assertSame([], $checker->check('false', ['type' => 'boolean', 'enum' => [false]], true));
        self::assertSame(
            'value is not one of the enumerated values',
            $checker->check('true', ['type' => 'boolean', 'enum' => [false]], true)[0]['message'] ?? '',
        );
    }

    public function testCoercionWithoutATypeLeavesStringsAlone(): void
    {
        self::assertSame([], new OpenApiSchemaChecker([])->check('42', ['enum' => ['42']], true));
    }

    public function testEmptyTypeArrayIsUnconstrained(): void
    {
        self::assertSame([], new OpenApiSchemaChecker([])->check('anything', ['type' => []], false));
    }

    public function testNullTypeRejectsNonNullValues(): void
    {
        $issues = new OpenApiSchemaChecker([])->check('x', ['type' => 'null'], false);
        self::assertSame([['pointer' => '', 'message' => 'expected null, got string']], $issues);
    }

    public function testCheckerRestoresTheErrorHandlerAroundInvalidPatterns(): void
    {
        // A handler installed BEFORE the checker call must be active again
        // after it: the scoped restore is part of the contract.
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = $errstr;

            return true;
        });

        try {
            new OpenApiSchemaChecker([])->check('x', ['type' => 'string', 'pattern' => '('], false);
            trigger_error('after-checker warning', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
        self::assertSame(['after-checker warning'], $captured);
    }

    public function testEmptyArrayIsStillCheckedForItemBounds(): void
    {
        $issues = new OpenApiSchemaChecker([])->check([], ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2], false);
        self::assertSame([['pointer' => '', 'message' => 'array has fewer than minItems 2']], $issues);
    }

    public function testListValuesSkipObjectChecks(): void
    {
        // A list-shaped value is not an object: additionalProperties must
        // not be applied to its numeric keys (no type declared, so the
        // type check cannot short-circuit first).
        $schema = ['additionalProperties' => false];
        self::assertSame([], new OpenApiSchemaChecker([])->check(['a'], $schema, false));
    }

    public function testNonArrayValuesSkipArrayChecks(): void
    {
        self::assertSame([], new OpenApiSchemaChecker([])->check('a', ['minItems' => 2], false));
    }

    public function testTwoAdditionalPropertiesAreBothReported(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false];
        $issues = new OpenApiSchemaChecker([])->check(['a' => 1, 'b' => 2, 'c' => 3], $schema, false);
        self::assertSame([
            ['pointer' => '', 'message' => "additional property 'b' is not allowed"],
            ['pointer' => '', 'message' => "additional property 'c' is not allowed"],
        ], $issues);
    }

    public function testAllOfBranchIssuesAccumulate(): void
    {
        $schema = ['allOf' => [['type' => 'string', 'maxLength' => 3], ['type' => 'string', 'pattern' => '^z+$']]];
        $issues = new OpenApiSchemaChecker([])->check('abcd', $schema, false);
        self::assertSame([
            ['pointer' => '', 'message' => 'string is longer than maxLength 3'],
            ['pointer' => '', 'message' => 'value does not match the required pattern'],
        ], $issues);
    }

    public function testGarbageCompositionBranchBeforeAValidOne(): void
    {
        $checker = new OpenApiSchemaChecker([]);
        self::assertSame([], $checker->check('x', ['oneOf' => ['garbage', ['type' => 'string']]], false));
        self::assertSame([], $checker->check('x', ['anyOf' => ['garbage', ['type' => 'string']]], false));
        self::assertSame([], $checker->check('x', ['allOf' => ['garbage', ['type' => 'string']]], false));
    }

    public function testAnyOfNestingIsDepthBounded(): void
    {
        $build = static function (int $levels): array {
            $schema = ['type' => 'string'];
            for ($i = 0; $i < $levels; ++$i) {
                $schema = ['anyOf' => [$schema]];
            }

            return $schema;
        };
        $checker = new OpenApiSchemaChecker([]);

        self::assertSame([], $checker->check('x', $build(63), false));
        $issues = $checker->check('x', $build(65), false);
        self::assertSame('value matches none of the anyOf branches', $issues[0]['message'] ?? '');
    }

    public function testAllOfNestingIsDepthBounded(): void
    {
        $build = static function (int $levels): array {
            $schema = ['type' => 'string'];
            for ($i = 0; $i < $levels; ++$i) {
                $schema = ['allOf' => [$schema]];
            }

            return $schema;
        };
        $checker = new OpenApiSchemaChecker([]);

        self::assertSame([], $checker->check('x', $build(63), false));
        $issues = $checker->check('x', $build(65), false);
        self::assertSame('schema nesting exceeds 64 levels', $issues[0]['message'] ?? '');
    }

    public function testRefSiblingMultiIssuesAreAllKept(): void
    {
        $schemas = ['Label' => ['type' => 'string']];
        $schema = [
            '$ref' => '#/components/schemas/Label',
            'maxLength' => 3,
            'pattern' => '^z+$',
            'format' => 'email',
        ];
        $issues = new OpenApiSchemaChecker($schemas)->check('abcd', $schema, false);
        self::assertSame([
            ['pointer' => '', 'message' => 'string is longer than maxLength 3'],
            ['pointer' => '', 'message' => 'value does not match the required pattern'],
            ['pointer' => '', 'message' => 'value is not a valid email'],
        ], $issues);
    }

    public function testDepthIssueCarriesTheAccumulatedPointer(): void
    {
        $schema = ['type' => 'object'];
        for ($i = 0; $i < 70; ++$i) {
            $schema = ['type' => 'object', 'properties' => ['c' => $schema]];
        }
        $value = [];
        for ($i = 0; $i < 65; ++$i) {
            $value = ['c' => $value];
        }
        $issues = new OpenApiSchemaChecker([])->check($value, $schema, false);
        self::assertSame([['pointer' => str_repeat('/c', 65), 'message' => 'schema nesting exceeds 64 levels']], $issues);
    }

    /*
     * Round-5 closers: the strictQuery mode (never exercised engine-side
     * before), integer scheme names in requirements (boot-validated shape
     * but unvalidated keys), a null body provider against a required body,
     * orphan path parameters, assoc-vs-array gate reach, and the
     * warning-free garbage-requestBody contract.
     */

    public function testStrictQueryRejectsUndeclaredKeysAfterDeclaredOnes(): void
    {
        $spec = $this->baseSpec([
            '/q' => ['get' => $this->operation('q', [
                ['name' => 'a', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions(strictQuery: true));

        $verdict = $gate->evaluate($this->request('GET', '/q', query: ['a' => '1', 'b' => 'x']));
        self::assertFalse($verdict->admitted);
        self::assertSame([
            ['in' => 'query', 'name' => 'b', 'pointer' => '', 'message' => "undeclared query parameter 'b' is not allowed"],
        ], $verdict->issues);

        $verdict = $gate->evaluate($this->request('GET', '/q', query: ['b' => 'x']));
        self::assertFalse($verdict->admitted);
        self::assertSame([
            ['in' => 'query', 'name' => 'b', 'pointer' => '', 'message' => "undeclared query parameter 'b' is not allowed"],
        ], $verdict->issues);

        self::assertTrue($gate->evaluate($this->request('GET', '/q', query: ['a' => '1']))->admitted);
    }

    public function testIntegerSchemeNamesAreReportedUnsatisfied(): void
    {
        // Requirement keys are not boot-validated (only the requirement
        // shape is), so an integer scheme name is a reachable input.
        $spec = $this->baseSpec([
            '/locked' => ['get' => $this->operation('locked', [], ['security' => [[0 => []]]])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/locked'));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
        self::assertSame('The security requirement (0) is not satisfied.', $verdict->detail);
    }

    public function testNullBodyProviderAgainstARequiredBody(): void
    {
        $spec = $this->baseSpec([
            '/b' => ['post' => $this->operation('postB', [], [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $request = new OpenApiGateRequest('POST', '/b', [], [], [], 'application/json', null, []);
        $verdict = $gate->evaluate($request);
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame('The request body is required.', $verdict->detail);
    }

    public function testOrphanPathParameterIsSkippedAndDoesNotAbortValidation(): void
    {
        // 'orphan' is declared as a path parameter but the template has no
        // {orphan} placeholder (the validator only checks the converse).
        $spec = $this->baseSpec([
            '/u/{id}' => ['get' => $this->operation('u', [
                ['name' => 'orphan', 'in' => 'path', 'required' => true, 'schema' => ['enum' => ['never']]],
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/u/5'));
        self::assertTrue($verdict->admitted);

        $verdict = $gate->evaluate($this->request('GET', '/u/abc'));
        self::assertFalse($verdict->admitted);
        self::assertSame([
            ['in' => 'path', 'name' => 'id', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testAssocShapedValuesSkipArrayChecks(): void
    {
        self::assertSame([], new OpenApiSchemaChecker([])->check(['k' => 'v'], ['minItems' => 2], false));
    }

    public function testArrayBoundAndItemIssuesAccumulate(): void
    {
        $issues = new OpenApiSchemaChecker([])->check(
            ['a', 5],
            ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3],
            false,
        );
        self::assertSame([
            ['pointer' => '', 'message' => 'array has fewer than minItems 3'],
            ['pointer' => '/1', 'message' => 'expected string, got integer'],
        ], $issues);
    }

    public function testAdditionalPropertiesSchemaIssuesAccumulate(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'integer']];
        $issues = new OpenApiSchemaChecker([])->check(['a' => 1, 'b' => 'x', 'c' => 'y'], $schema, false);
        self::assertSame([
            ['pointer' => '/b', 'message' => 'expected integer, got string'],
            ['pointer' => '/c', 'message' => 'expected integer, got string'],
        ], $issues);
    }

    public function testAdditionalPropertiesNestingIsDepthBounded(): void
    {
        $build = static function (int $levels): array {
            $schema = ['type' => 'integer'];
            for ($i = 0; $i < $levels; ++$i) {
                $schema = ['type' => 'object', 'additionalProperties' => $schema];
            }

            return $schema;
        };
        $nest = static function (int $levels): array {
            $value = 1;
            for ($i = 0; $i < $levels; ++$i) {
                $value = ['k' => $value];
            }
            assert(is_array($value));

            return $value;
        };
        $checker = new OpenApiSchemaChecker([]);

        self::assertSame([], $checker->check($nest(63), $build(63), false));
        $issues = $checker->check($nest(65), $build(65), false);
        self::assertSame('schema nesting exceeds 64 levels', $issues[0]['message'] ?? '');
    }

    public function testGarbageRequestBodyIsWarningFree(): void
    {
        $spec = $this->baseSpec([
            '/b' => ['post' => ['operationId' => 'b', 'responses' => ['200' => ['description' => 'ok']], 'requestBody' => 'garbage']],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = $errstr;

            return true;
        });

        try {
            $verdict = $gate->evaluate($this->request('POST', '/b', contentType: 'text/plain', body: 'anything'));
        } finally {
            restore_error_handler();
        }
        self::assertTrue($verdict->admitted);
        self::assertSame([], $captured);
    }

    public function testRootPathTemplateMatchesTheRootRequest(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->baseSpec(['/' => ['get' => $this->operation('root')]]), new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('root', $verdict->operation['operationId']);
    }

    /*
     * Round-7 closers after the SonarCloud split: contradiction bounds,
     * declared non-JSON request bodies, garbage type-array entries and
     * malformed placeholder segments (digit-leading / invalid-character).
     */

    public function testContradictingBoundsProduceBothIssues(): void
    {
        $issues = new OpenApiSchemaChecker([])->check(
            5,
            ['type' => 'integer', 'minimum' => 10, 'maximum' => 0],
            false,
        );
        self::assertSame([
            ['pointer' => '', 'message' => 'value is below the minimum 10'],
            ['pointer' => '', 'message' => 'value is above the maximum 0'],
        ], $issues);
    }

    public function testDeclaredNonJsonRequestBodyIsNotSchemaChecked(): void
    {
        $spec = $this->baseSpec([
            '/b' => ['post' => $this->operation('postB', [], [
                'requestBody' => ['required' => true, 'content' => ['text/plain' => ['schema' => ['type' => 'integer']]]],
            ])],
        ]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('POST', '/b', contentType: 'text/plain', body: 'not json at all'));
        self::assertTrue($verdict->admitted);
    }

    public function testTypeArrayGarbageEntriesAreSkipped(): void
    {
        self::assertSame([], new OpenApiSchemaChecker([])->check('x', ['type' => ['string', 42]], false));
    }

    public function testDigitLeadingPlaceholderIsAStaticLiteral(): void
    {
        $spec = $this->baseSpec(['/d/{9abc}' => ['get' => $this->operation('d')]]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        // '{9abc}' is not a valid identifier placeholder: the segment is a
        // static literal, so nothing dynamic matches.
        $verdict = $gate->evaluate($this->request('GET', '/d/9abc'));
        self::assertTrue($verdict->admitted);
        self::assertNull($verdict->operation);
    }

    public function testInvalidCharacterPlaceholderIsAStaticLiteral(): void
    {
        $spec = $this->baseSpec(['/e/{a-b}' => ['get' => $this->operation('e')]]);
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/e/x'));
        self::assertTrue($verdict->admitted);
        self::assertNull($verdict->operation);
    }

    /**
     * @return array<string, mixed>
     */
    private function twoIntTemplatesSpec(): array
    {
        return $this->baseSpec([
            '/users/{b}' => ['get' => $this->operation('b', [
                ['name' => 'b', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ])],
            '/users/{a}' => ['get' => $this->operation('a', [
                ['name' => 'a', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mixedTemplatesSpec(): array
    {
        return $this->baseSpec([
            '/items/{b}' => ['get' => $this->operation('b', [
                ['name' => 'b', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ])],
            '/items/{a}' => ['get' => $this->operation('a', [
                ['name' => 'a', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ])],
        ]);
    }

    /**
     * @param array<string, mixed> $paths
     *
     * @return array<string, mixed>
     */
    private function baseSpec(array $paths): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Debt API', 'version' => '1.0.0'],
            'paths' => $paths,
        ];
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @param array<string, mixed>      $overrides
     *
     * @return array<string, mixed>
     */
    private function operation(string $operationId, array $parameters = [], array $overrides = []): array
    {
        return $overrides + [
            'operationId' => $operationId,
            'parameters' => $parameters,
            'responses' => ['200' => ['description' => 'ok']],
        ];
    }

    /**
     * @param list<array<string, mixed>> $requirements
     * @param array<string, mixed>      $schemes
     *
     * @return array<string, mixed>
     */
    private function lockedSpec(array $requirements, array $schemes): array
    {
        $spec = $this->baseSpec([
            '/locked' => ['get' => $this->operation('locked', [], ['security' => $requirements])],
        ]);
        $spec['components'] = ['securitySchemes' => $schemes];

        return $spec;
    }

    /**
     * @return array{query: array<string, string>, headers: array<string, string>, cookies: array<string, string>}
     */
    private function carrier(string $location, string $value): array
    {
        $query = [];
        $headers = [];
        $cookies = [];
        match ($location) {
            'query' => $query['X-Key'] = $value,
            'header' => $headers['x-key'] = $value,
            'cookie' => $cookies['X-Key'] = $value,
            default => throw new \LogicException("unknown location {$location}"),
        };

        return ['query' => $query, 'headers' => $headers, 'cookies' => $cookies];
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @param array<string, mixed>  $cookies
     * @param array<string, mixed>  $attributes
     */
    private function request(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        array $cookies = [],
        string $contentType = '',
        ?string $body = null,
        array $attributes = [],
    ): OpenApiGateRequest {
        return new OpenApiGateRequest(
            $method,
            $path,
            $query,
            $headers,
            $cookies,
            $contentType,
            $body === null ? null : static fn (): string => $body,
            $attributes,
        );
    }
}
