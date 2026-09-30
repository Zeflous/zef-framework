<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.33.0 — OpenAPI runtime gate: the 12-boundary parity
 * matrix of docs/OPENAPI-GATE-PARITY.md, pinned one row at a time against
 * the engine. If behaviour and the matrix disagree, the code is wrong.
 *
 * The fixture is built through the real SpecificationBuilder (the golden
 * pattern), so the enforced document is exactly the shape the framework
 * emits; hand-written spec arrays cover the cases the builder cannot
 * express (global security, apiKey names, empty requirement objects).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\MediaType;
use Zef\Framework\OpenApi\OpenApiGateException;
use Zef\Framework\OpenApi\OpenApiGateOptions;
use Zef\Framework\OpenApi\OpenApiGateRequest;
use Zef\Framework\OpenApi\OpenApiRequestGate;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\Parameter;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\RequestBody;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecurityRequirement;
use Zef\Framework\OpenApi\SecurityScheme;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\SpecificationBuilder;

/**
 * @internal
 */
final class OpenApiGateMatrixTest extends TestCase
{
    private OpenApiRequestGate $gate;

    protected function setUp(): void
    {
        $this->gate = OpenApiRequestGate::fromSpec($this->matrixSpec(), new OpenApiGateOptions());
    }

    public function testB1UnknownPathPassesThroughWithNullOperation(): void
    {
        $verdict = $this->gate->evaluate($this->request('GET', '/unknown'));
        self::assertTrue($verdict->admitted);
        self::assertNull($verdict->operation);

        // No template has three segments — still a pass-through.
        $verdict = $this->gate->evaluate($this->request('GET', '/users/7/extra'));
        self::assertTrue($verdict->admitted);
        self::assertNull($verdict->operation);
    }

    public function testB1RootAndEmptySegmentCollapse(): void
    {
        // '/ping/' trims to the same single segment as '/ping'.
        $verdict = $this->gate->evaluate($this->request('GET', '/ping/'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('ping', $verdict->operation['operationId']);
    }

    public function testB2MethodNotDocumentedIs405WithAllowHeader(): void
    {
        $verdict = $this->gate->evaluate($this->request('DELETE', '/users/7'));
        self::assertFalse($verdict->admitted);
        self::assertSame(405, $verdict->status);
        self::assertSame('Method Not Allowed', $verdict->title);
        self::assertSame("Method 'DELETE' is not documented for this path.", $verdict->detail);
        self::assertSame(['Allow' => 'GET, HEAD'], $verdict->headers);
        self::assertSame(['GET', 'HEAD'], $verdict->extensions['allowed']);
        self::assertSame([], $verdict->issues);
    }

    public function testB2HeadFallsBackToGet(): void
    {
        $verdict = $this->gate->evaluate($this->request('HEAD', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('GET', $verdict->operation['method']);
        self::assertSame('getUser', $verdict->operation['operationId']);
    }

    public function testB2DeclaredHeadWinsOverGetFallback(): void
    {
        $verdict = $this->gate->evaluate($this->request('HEAD', '/ping'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('HEAD', $verdict->operation['method']);
        self::assertSame('pingHead', $verdict->operation['operationId']);
    }

    public function testB3PathParamSchemaViolationIs400(): void
    {
        $verdict = $this->gate->evaluate($this->request('GET', '/users/abc'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame('Path parameters violate the documented schema.', $verdict->detail);
        self::assertSame([
            ['in' => 'path', 'name' => 'id', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testB3ConstraintMinimumIsASchemaToo(): void
    {
        $verdict = $this->gate->evaluate($this->request('GET', '/users/0'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'path', 'name' => 'id', 'pointer' => '', 'message' => 'value is below the minimum 1'],
        ], $verdict->issues);
    }

    public function testB3PercentEncodedValueIsDecodedBeforeValidation(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/%31%32%33', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame(['id' => '123'], $verdict->operation['pathParams']);
    }

    public function testB4RequiredQueryParameterMissing(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'query', 'name' => 'expand', 'pointer' => '', 'message' => "required query parameter 'expand' is missing"],
        ], $verdict->issues);
    }

    public function testB5QueryValuesAreCoercedToSchemaTypes(): void
    {
        $base = ['expand' => 'x'];
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: $base + ['page' => '2'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($verdict->admitted);

        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: $base + ['page' => 'abc'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'query', 'name' => 'page', 'pointer' => '', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testB5QueryArrayAcceptsRepeatedKeysAndCommaSeparated(): void
    {
        $base = ['expand' => 'x'];
        $repeated = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: $base + ['tag' => ['a', 'b']], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($repeated->admitted);

        $comma = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: $base + ['tag' => 'a,b'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($comma->admitted);

        $duplicates = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: $base + ['tag' => 'a,a'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertFalse($duplicates->admitted);
        self::assertSame([
            ['in' => 'query', 'name' => 'tag', 'pointer' => '', 'message' => 'array items are not unique'],
        ], $duplicates->issues);
    }

    public function testB6RequiredHeaderMissing(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'header', 'name' => 'X-Request-Id', 'pointer' => '', 'message' => "required header parameter 'X-Request-Id' is missing"],
        ], $verdict->issues);
    }

    public function testB6HeaderSchemaViolation(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abcdefghijk'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame([
            ['in' => 'header', 'name' => 'X-Request-Id', 'pointer' => '', 'message' => 'string is longer than maxLength 8'],
        ], $verdict->issues);
    }

    public function testB7UnsupportedMediaTypeIs415(): void
    {
        $verdict = $this->gate->evaluate($this->request('POST', '/users', contentType: 'text/plain', body: '{}'));
        self::assertFalse($verdict->admitted);
        self::assertSame(415, $verdict->status);
        self::assertSame('Unsupported Media Type', $verdict->title);
        self::assertSame("Media type 'text/plain' is not offered by this operation.", $verdict->detail);
        self::assertSame(['application/json'], $verdict->extensions['supported']);
        self::assertSame([
            ['in' => 'body', 'name' => 'text/plain', 'pointer' => '', 'message' => "media type 'text/plain' is not documented"],
        ], $verdict->issues);
    }

    public function testB7MissingContentTypeWithBodyIs415(): void
    {
        $verdict = $this->gate->evaluate($this->request('POST', '/users', body: '{}'));
        self::assertFalse($verdict->admitted);
        self::assertSame(415, $verdict->status);
        self::assertSame('A Content-Type header is required for this operation.', $verdict->detail);
        self::assertSame(['application/json'], $verdict->extensions['supported']);
    }

    public function testB7ContentTypeParametersAreStripped(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('POST', '/users', contentType: 'application/json; charset=utf-8', body: '{"id":42,"email":"a@b.c"}'),
        );
        self::assertTrue($verdict->admitted);
    }

    public function testB8RequiredBodyAbsent(): void
    {
        foreach (['', '   '] as $emptyBody) {
            $verdict = $this->gate->evaluate($this->request('POST', '/users', contentType: 'application/json', body: $emptyBody));
            self::assertFalse($verdict->admitted);
            self::assertSame(400, $verdict->status);
            self::assertSame('The request body is required.', $verdict->detail);
            self::assertSame([
                ['in' => 'body', 'name' => '', 'pointer' => '', 'message' => 'request body is required'],
            ], $verdict->issues);
        }
    }

    public function testB8OptionalBodyMayBeAbsent(): void
    {
        $verdict = $this->gate->evaluate($this->request('PATCH', '/users', contentType: 'application/json', body: ''));
        self::assertTrue($verdict->admitted);
    }

    public function testB9BodySchemaViolationsCarryPointers(): void
    {
        $verdict = $this->gate->evaluate($this->request('POST', '/users', contentType: 'application/json', body: '{"id":"x"}'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame('The request body violates the documented schema.', $verdict->detail);
        self::assertSame([
            ['in' => 'body', 'name' => 'application/json', 'pointer' => '', 'message' => "missing required property 'email'"],
            ['in' => 'body', 'name' => 'application/json', 'pointer' => '/id', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testB9MalformedJsonBody(): void
    {
        $verdict = $this->gate->evaluate($this->request('POST', '/users', contentType: 'application/json', body: '{'));
        self::assertFalse($verdict->admitted);
        self::assertSame(400, $verdict->status);
        self::assertSame('The request body is not valid JSON.', $verdict->detail);
        self::assertSame([
            ['in' => 'body', 'name' => 'application/json', 'pointer' => '', 'message' => 'malformed JSON body'],
        ], $verdict->issues);
    }

    public function testB9BodyTypesAreStrictWithoutCoercion(): void
    {
        $verdict = $this->gate->evaluate($this->request('POST', '/users', contentType: 'application/json', body: '{"id":"42","email":"a@b.c"}'));
        self::assertFalse($verdict->admitted);
        self::assertSame([
            ['in' => 'body', 'name' => 'application/json', 'pointer' => '/id', 'message' => 'expected integer, got string'],
        ], $verdict->issues);
    }

    public function testB9ValidBodyIsAdmitted(): void
    {
        $verdict = $this->gate->evaluate($this->request('POST', '/users', contentType: 'application/json', body: '{"id":42,"email":"a@b.c"}'));
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('createUser', $verdict->operation['operationId']);
    }

    public function testB9OperationWithoutRequestBodyNeverReadsTheBody(): void
    {
        $request = new OpenApiGateRequest(
            'GET',
            '/ping',
            [],
            [],
            [],
            '',
            static fn (): ?string => throw new \LogicException('the body must not be read'),
            [],
        );
        $verdict = $this->gate->evaluate($request);
        self::assertTrue($verdict->admitted);
    }

    public function testB10AnonymousIs401(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
        self::assertSame('Unauthorized', $verdict->title);
        self::assertSame('The security requirement (bearer) is not satisfied.', $verdict->detail);
        self::assertSame([
            ['in' => 'security', 'name' => 'bearer', 'pointer' => '', 'message' => "security scheme 'bearer' is not satisfied"],
        ], $verdict->issues);
    }

    public function testB10IdentityAttributeSatisfiesAnyScheme(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($verdict->admitted);

        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.security.principal' => 'u1']),
        );
        self::assertTrue($verdict->admitted);
    }

    public function testB10AnonymousIdentityIsIgnored(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'anonymous']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
    }

    public function testB10BearerHeaderIsAcceptableEvidence(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc', 'authorization' => 'Bearer tok']),
        );
        self::assertTrue($verdict->admitted);
    }

    public function testB10WrongEvidenceSchemeIsRejected(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc', 'authorization' => 'Basic dXNlcjpwYXNz']),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
    }

    public function testB10ScopesAreDelegatedWithoutGrants(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($verdict->admitted);
    }

    public function testB10GrantedScopesSatisfyTheRequirement(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1', 'zef.security.scopes' => ['users:read']]),
        );
        self::assertTrue($verdict->admitted);
    }

    public function testB10MissingScopeIs403(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1', 'zef.security.scopes' => ['other:scope']]),
        );
        self::assertFalse($verdict->admitted);
        self::assertSame(403, $verdict->status);
        self::assertSame('Forbidden', $verdict->title);
    }

    public function testB10OperationWithoutSecurityIsAnonymousAdmissible(): void
    {
        self::assertTrue($this->gate->evaluate($this->request('GET', '/ping'))->admitted);
    }

    public function testB10AlternativeRequirementsAreOr(): void
    {
        $anonymous = $this->gate->evaluate($this->request('GET', '/admin'));
        self::assertFalse($anonymous->admitted);
        self::assertSame(401, $anonymous->status);
        // Deterministic detail: the scheme names of the FIRST alternative.
        self::assertSame('The security requirement (bearer) is not satisfied.', $anonymous->detail);

        $withBearer = $this->gate->evaluate($this->request('GET', '/admin', headers: ['authorization' => 'Bearer tok']));
        self::assertTrue($withBearer->admitted);
    }

    public function testB10ApiKeyEvidenceInEveryLocation(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->apiKeySpec('header'), new OpenApiGateOptions());
        self::assertFalse($gate->evaluate($this->request('GET', '/locked'))->admitted);
        // Header lookups are lowercase — the middleware normalizes PSR-7
        // header names, so the engine's map is keyed lowercase.
        self::assertTrue($gate->evaluate($this->request('GET', '/locked', headers: ['x-key' => 'secret']))->admitted);

        $gate = OpenApiRequestGate::fromSpec($this->apiKeySpec('query'), new OpenApiGateOptions());
        self::assertFalse($gate->evaluate($this->request('GET', '/locked'))->admitted);
        self::assertTrue($gate->evaluate($this->request('GET', '/locked', query: ['X-Key' => 'secret']))->admitted);

        $gate = OpenApiRequestGate::fromSpec($this->apiKeySpec('cookie'), new OpenApiGateOptions());
        self::assertFalse($gate->evaluate($this->request('GET', '/locked'))->admitted);
        self::assertTrue($gate->evaluate($this->request('GET', '/locked', cookies: ['X-Key' => 'secret']))->admitted);
    }

    public function testB10ApiKeyWithoutNameHasNoEvidencePath(): void
    {
        $spec = $this->apiKeySpec('header');
        $spec['components']['securitySchemes']['key'] = ['type' => 'apiKey', 'in' => 'header'];
        $gate = OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/locked', headers: ['X-Key' => 'secret']));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);
    }

    public function testB10GlobalSecurityFallsBackUntilOverridden(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->globalSecuritySpec(), new OpenApiGateOptions());

        $denied = $gate->evaluate($this->request('GET', '/a'));
        self::assertFalse($denied->admitted);
        self::assertSame(401, $denied->status);

        // operation-level security: [] overrides the global requirement.
        self::assertTrue($gate->evaluate($this->request('GET', '/b'))->admitted);
    }

    public function testB10EmptyRequirementObjectIsSatisfied(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->emptyRequirementSpec(), new OpenApiGateOptions());
        self::assertTrue($gate->evaluate($this->request('GET', '/open'))->admitted);
    }

    public function testB10Oauth2HasNoCheapEvidence(): void
    {
        $gate = OpenApiRequestGate::fromSpec($this->oauth2Spec(), new OpenApiGateOptions());

        $verdict = $gate->evaluate($this->request('GET', '/locked', headers: ['authorization' => 'Bearer tok']));
        self::assertFalse($verdict->admitted);
        self::assertSame(401, $verdict->status);

        $verdict = $gate->evaluate($this->request('GET', '/locked', attributes: ['zef.auth.identity' => 'u1']));
        self::assertTrue($verdict->admitted);
    }

    public function testB11UndocumentedStatusIsAnIssue(): void
    {
        $issues = $this->gate->checkResponse($this->responses(), 500, 'application/json', '{"id":1}');
        self::assertSame([
            ['in' => 'response', 'name' => '500', 'pointer' => '', 'message' => 'response status 500 is not documented'],
        ], $issues);
    }

    public function testB11DefaultAndRangeKeysMatch(): void
    {
        $default = ['default' => ['description' => 'fallback', 'content' => ['application/json' => ['schema' => ['type' => 'integer']]]]];
        self::assertSame([], $this->gate->checkResponse($default, 503, 'application/json', '42'));

        $range = ['5XX' => ['description' => 'server error']];
        self::assertSame([], $this->gate->checkResponse($range, 503, 'application/json', ''));
    }

    public function testB11BodyWithoutDeclaredContentIsAnIssue(): void
    {
        $issues = $this->gate->checkResponse($this->responses(), 404, 'application/json', 'surprising payload');
        self::assertSame([
            ['in' => 'response', 'name' => '', 'pointer' => '', 'message' => 'response body is present but no content is documented'],
        ], $issues);
    }

    public function testB11EmptyBodySkipsValidation(): void
    {
        self::assertSame([], $this->gate->checkResponse($this->responses(), 200, 'application/json', ''));
        self::assertSame([], $this->gate->checkResponse($this->responses(), 404, '', ''));
    }

    public function testB11MissingContentTypeOnDocumentedContent(): void
    {
        $issues = $this->gate->checkResponse($this->responses(), 200, '', 'payload');
        self::assertSame([
            ['in' => 'response', 'name' => '', 'pointer' => '', 'message' => 'response has no Content-Type header'],
        ], $issues);
    }

    public function testB11UndocumentedMediaType(): void
    {
        $issues = $this->gate->checkResponse($this->responses(), 200, 'text/plain', 'payload');
        self::assertSame([
            ['in' => 'response', 'name' => 'text/plain', 'pointer' => '', 'message' => "response media type 'text/plain' is not documented"],
        ], $issues);
    }

    public function testB11DeclaredNonJsonMediaTypeIsNotSchemaChecked(): void
    {
        $responses = ['200' => ['description' => 'ok', 'content' => ['text/plain' => ['schema' => ['type' => 'integer']]]]];
        self::assertSame([], $this->gate->checkResponse($responses, 200, 'text/plain', 'not a number'));
    }

    public function testB11MalformedJsonResponseBody(): void
    {
        $issues = $this->gate->checkResponse($this->responses(), 200, 'application/json', '{');
        self::assertSame([
            ['in' => 'response', 'name' => 'application/json', 'pointer' => '', 'message' => 'response body is not valid JSON'],
        ], $issues);
    }

    public function testB11ResponseBodySchemaViolations(): void
    {
        $issues = $this->gate->checkResponse($this->responses(), 200, 'application/json', '{"id":"x"}');
        self::assertSame([
            ['in' => 'response', 'name' => 'application/json', 'pointer' => '', 'message' => "missing required property 'email'"],
            ['in' => 'response', 'name' => 'application/json', 'pointer' => '/id', 'message' => 'expected integer, got string'],
        ], $issues);

        self::assertSame([], $this->gate->checkResponse($this->responses(), 200, 'application/json', '{"id":1,"email":"a@b.c"}'));
    }

    public function testB12InvalidDocumentFailsConstruction(): void
    {
        try {
            OpenApiRequestGate::fromSpec(['openapi' => 'nope'], new OpenApiGateOptions());
            self::fail('OpenApiGateException expected');
        } catch (OpenApiGateException $exception) {
            self::assertStringContainsString('not enforceable', $exception->getMessage());
            self::assertContains('Field "openapi" must be a semver string like "3.1.0".', $exception->errors);
        }
    }

    public function testB12InvalidSecuritySchemeIsRejectedThroughValidatorReuse(): void
    {
        $spec = $this->apiKeySpec('header');
        $spec['components']['securitySchemes']['key'] = ['type' => 'apiKey'];

        try {
            OpenApiRequestGate::fromSpec($spec, new OpenApiGateOptions());
            self::fail('OpenApiGateException expected');
        } catch (OpenApiGateException $exception) {
            self::assertContains("Security scheme 'key' of type apiKey must define in: query|header|cookie.", $exception->errors);
        }
    }

    public function testPrecedence405BeatsSecurity(): void
    {
        $verdict = $this->gate->evaluate($this->request('DELETE', '/users/7'));
        self::assertSame(405, $verdict->status);
    }

    public function testPrecedenceSecurityBeatsValidation(): void
    {
        // Missing the required `expand` query parameter AND anonymous —
        // the gate answers 401, never leaking the contract details.
        $verdict = $this->gate->evaluate($this->request('GET', '/users/7'));
        self::assertSame(401, $verdict->status);
    }

    public function testAdmittedContextCarriesTheMatchedOperation(): void
    {
        $verdict = $this->gate->evaluate(
            $this->request('GET', '/users/7', query: ['expand' => 'x'], headers: ['x-request-id' => 'abc'], attributes: ['zef.auth.identity' => 'u1']),
        );
        self::assertTrue($verdict->admitted);
        self::assertNotNull($verdict->operation);
        self::assertSame('getUser', $verdict->operation['operationId']);
        self::assertSame('/users/{id}', $verdict->operation['path']);
        self::assertSame('GET', $verdict->operation['method']);
        self::assertSame(['id' => '7'], $verdict->operation['pathParams']);
        self::assertArrayHasKey(200, $verdict->operation['responses']);
        self::assertArrayHasKey(404, $verdict->operation['responses']);
    }

    /**
     * @return array<string, mixed>
     */
    private function matrixSpec(): array
    {
        $builder = new SpecificationBuilder(new Info(title: 'Gate Matrix API', version: '1.0.0'));
        $builder->addSecurityScheme('bearer', new SecurityScheme(SecuritySchemeType::Http, scheme: 'bearer'));
        $builder->addSchema('User', new Schema(
            type: SchemaType::Object,
            required: ['id', 'email'],
            properties: [
                'id' => new Schema(type: SchemaType::Integer, minimum: 1),
                'email' => new Schema(type: SchemaType::String, format: 'email'),
                'status' => new Schema(type: SchemaType::String, enum: ['active', 'off']),
                'tags' => new Schema(type: SchemaType::Array, items: new Schema(type: SchemaType::String), uniqueItems: true, minItems: 1),
            ],
        ));
        $builder->addOperation(new Operation(
            operationId: 'getUser',
            method: 'GET',
            path: '/users/{id}',
            responses: [
                '200' => new Response('User found', ['application/json' => new Schema(ref: '#/components/schemas/User')]),
                '404' => new Response('Missing'),
            ],
            parameters: [
                new Parameter('id', ParameterLocation::Path, new Schema(type: SchemaType::Integer, minimum: 1), 'User id'),
                new Parameter('expand', ParameterLocation::Query, new Schema(type: SchemaType::String), '', required: true),
                new Parameter('page', ParameterLocation::Query, new Schema(type: SchemaType::Integer, minimum: 1)),
                new Parameter('tag', ParameterLocation::Query, new Schema(type: SchemaType::Array, items: new Schema(type: SchemaType::String), uniqueItems: true)),
                new Parameter('X-Request-Id', ParameterLocation::Header, new Schema(type: SchemaType::String, maxLength: 8), '', required: true),
            ],
            security: [new SecurityRequirement(['bearer' => ['users:read']])],
        ));
        $builder->addOperation(new Operation(
            operationId: 'createUser',
            method: 'POST',
            path: '/users',
            responses: ['201' => new Response('Created')],
            requestBody: new RequestBody([MediaType::Json->value => new Schema(ref: '#/components/schemas/User')], 'New user', true),
        ));
        $builder->addOperation(new Operation(
            operationId: 'patchUser',
            method: 'PATCH',
            path: '/users',
            responses: ['204' => new Response('Patched')],
            requestBody: new RequestBody([MediaType::Json->value => new Schema(type: SchemaType::String)], 'Optional label', false),
        ));
        $builder->addOperation(new Operation(
            operationId: 'ping',
            method: 'GET',
            path: '/ping',
            responses: ['200' => new Response('Pong')],
        ));
        $builder->addOperation(new Operation(
            operationId: 'pingHead',
            method: 'HEAD',
            path: '/ping',
            responses: ['200' => new Response('Pong')],
        ));
        $builder->addOperation(new Operation(
            operationId: 'adminIndex',
            method: 'GET',
            path: '/admin',
            responses: ['200' => new Response('Admin')],
            security: [
                new SecurityRequirement(['bearer' => []]),
                new SecurityRequirement(['key' => []]),
            ],
        ));

        return $builder->build();
    }

    /**
     * Hand-written apiKey document: the builder's SecurityScheme value
     * object cannot express the apiKey `name` field, so the evidence path
     * is pinned through a hand-edited document instead.
     *
     * @return array<string, mixed>
     */
    private function apiKeySpec(string $in): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Key API', 'version' => '1.0.0'],
            'paths' => [
                '/locked' => ['get' => [
                    'operationId' => 'locked',
                    'responses' => ['200' => ['description' => 'ok']],
                    'security' => [['key' => []]],
                ]],
            ],
            'components' => [
                'securitySchemes' => [
                    'key' => ['type' => 'apiKey', 'in' => $in, 'name' => 'X-Key'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function globalSecuritySpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Global API', 'version' => '1.0.0'],
            'security' => [['bearer' => []]],
            'paths' => [
                '/a' => ['get' => [
                    'operationId' => 'a',
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/b' => ['get' => [
                    'operationId' => 'b',
                    'responses' => ['200' => ['description' => 'ok']],
                    'security' => [],
                ]],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRequirementSpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Open API', 'version' => '1.0.0'],
            'paths' => [
                '/open' => ['get' => [
                    'operationId' => 'open',
                    'responses' => ['200' => ['description' => 'ok']],
                    'security' => [[]],
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function oauth2Spec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'OAuth API', 'version' => '1.0.0'],
            'paths' => [
                '/locked' => ['get' => [
                    'operationId' => 'locked',
                    'responses' => ['200' => ['description' => 'ok']],
                    'security' => [['oauth' => []]],
                ]],
            ],
            'components' => [
                'securitySchemes' => [
                    'oauth' => [
                        'type' => 'oauth2',
                        'flows' => ['clientCredentials' => ['tokenUrl' => 'https://sso.example.test/token', 'scopes' => []]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function responses(): array
    {
        return [
            200 => ['description' => 'User found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/User']]]],
            404 => ['description' => 'Missing'],
        ];
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
            $body === null ? null : static fn (): ?string => $body,
            $attributes,
        );
    }
}
