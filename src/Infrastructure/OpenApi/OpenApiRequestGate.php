<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The OpenAPI runtime gate engine — one evaluate() per request, zero
 * re-parsing of the document (the index is compiled once at boot).
 *
 * Decision order is the parity matrix's status precedence, made total:
 *   B1 pass-through (no template matched)
 *   → B2 405 (method not documented; Allow header, router parity)
 *   → B3 template choice (path-parameter schemas; 400 on violation —
 *      parity with the router's RouteConstraintException 400)
 *   → B10 401/403 (security before validation: no contract details
 *      leak to anonymous callers)
 *   → B7 415 (media type must be known before the body can be judged)
 *   → B4/B5/B6/B8/B9 400 (parameters + body, issues collected in
 *      declaration order, body issues last)
 *
 * Construction is fail-closed (B12): the document must pass
 * OpenApiSpecValidator::validate() — which includes the
 * OpenApiSecurityValidator scheme invariants the engine relies on.
 *
 * @phpstan-type GateIssue array{in: string, name: string, pointer: string, message: string}
 * @phpstan-type GateOperationContext array{
 *     operationId: string,
 *     path: string,
 *     method: string,
 *     pathParams: array<string, string>,
 *     responses: array<mixed, mixed>,
 * }
 * @phpstan-type GateBodyRejection array{status: int, detail: string, issues: list<GateIssue>, extensions: array<string, mixed>}
 * @phpstan-type GateSegment array{dynamic: bool, name?: string, value?: string}
 * @phpstan-type GateTemplate array{
 *     path: string,
 *     segments: list<GateSegment>,
 *     methods: array<string, array<mixed, mixed>>,
 * }
 * @phpstan-type GatePathMatch array{template: GateTemplate, params: array<string, string>}
 */
final readonly class OpenApiRequestGate
{
    private function __construct(
        private OpenApiGateIndex $index,
        private OpenApiSchemaChecker $checker,
        private OpenApiGateOptions $options,
    ) {}

    /**
     * @param array<string, mixed> $spec the built OpenAPI document
     *
     * @throws OpenApiGateException when the document is not enforceable (B12)
     */
    public static function fromSpec(array $spec, OpenApiGateOptions $options): self
    {
        $errors = new OpenApiSpecValidator()->validate($spec);
        if ($errors !== []) {
            throw new OpenApiGateException(
                'The OpenAPI document is not enforceable by the runtime gate: ' . implode(' ', $errors),
                $errors,
            );
        }

        $index = OpenApiGateIndex::fromSpec($spec);

        return new self($index, new OpenApiSchemaChecker($index->schemas), $options);
    }

    public function evaluate(OpenApiGateRequest $request): OpenApiGateVerdict
    {
        $matches = $this->index->matchPath($request->path);
        if ($matches === []) {
            // B1: unknown path — pass through, the router owns 404.
            return OpenApiGateVerdict::admitted(null);
        }

        $candidates = $this->methodCandidates($request->method, $matches);
        if ($candidates === []) {
            // B2: 405 + Allow, same method set the router would report.
            $templates = array_map(static fn (array $match): array => $match['template'], $matches);
            $allowed = $this->index->allowedMethods($templates);

            return OpenApiGateVerdict::rejected(
                405,
                "Method '{$request->method}' is not documented for this path.",
                [],
                ['Allow' => implode(', ', $allowed)],
                ['allowed' => $allowed],
            );
        }

        // B3: the first (sorted) template whose path parameters validate wins.
        $chosen = null;
        $firstPathIssues = null;
        foreach ($candidates as $candidate) {
            $issues = $this->pathParameterIssues($candidate['operation'], $candidate['params']);
            if ($issues === []) {
                $chosen = $candidate;

                break;
            }
            $firstPathIssues ??= $issues;
        }
        if ($chosen === null) {
            return OpenApiGateVerdict::rejected(
                400,
                'Path parameters violate the documented schema.',
                $firstPathIssues ?? [],
            );
        }

        $operation = $chosen['operation'];

        // B10: security before validation — precedence 401/403 over 415/400.
        $security = $this->securityVerdict($operation, $request);
        if ($security !== null) {
            return $security;
        }

        $parameterIssues = $this->parameterIssues($operation, $request);
        if ($this->options->strictQuery) {
            // Undeclared-query rejections append after the declared ones.
            $parameterIssues = [...$parameterIssues, ...$this->undeclaredQueryIssues($operation, $request)];
        }

        $body = $this->bodyRejection($operation, $request);
        if ($body !== null && $body['status'] === 415) {
            return OpenApiGateVerdict::rejected(415, $body['detail'], $body['issues'], [], $body['extensions']);
        }

        $issues = [...$parameterIssues, ...($body['issues'] ?? [])];
        if ($issues !== []) {
            $detail = 'The request violates the documented API contract.';
            if ($parameterIssues === [] && $body !== null) {
                $detail = $body['detail'];
            }

            return OpenApiGateVerdict::rejected(400, $detail, $issues);
        }

        /** @var GateOperationContext $context */
        $context = [
            'operationId' => is_string($operation['operationId'] ?? null) ? $operation['operationId'] : '',
            'path' => $chosen['template']['path'],
            'method' => strtoupper($chosen['method']),
            'pathParams' => $chosen['params'],
            'responses' => is_array($operation['responses'] ?? null) ? $operation['responses'] : [],
        ];

        return OpenApiGateVerdict::admitted($context);
    }

    /**
     * Boundary B11: the response contract of the matched operation.
     *
     * @param array<mixed, mixed> $responses the operation's responses map
     *
     * @return list<GateIssue>
     */
    public function checkResponse(array $responses, int $status, string $mediaType, ?string $body): array
    {
        $key = $this->responseKey($responses, $status);
        if ($key === null) {
            return [[
                'in' => 'response',
                'name' => (string) $status,
                'pointer' => '',
                'message' => "response status {$status} is not documented",
            ]];
        }
        $response = $responses[$key] ?? null;
        if (!is_array($response)) {
            // Defensive: the boot-time validator requires response objects.
            return [];
        }

        $content = $response['content'] ?? null;
        if (!is_array($content) || $content === []) {
            if ($body !== null && trim($body) !== '') {
                return [[
                    'in' => 'response',
                    'name' => '',
                    'pointer' => '',
                    'message' => 'response body is present but no content is documented',
                ]];
            }

            return [];
        }
        if ($body === null || trim($body) === '') {
            // Nothing to validate: an empty body cannot violate a schema.
            return [];
        }

        $normalized = strtolower(trim(explode(';', $mediaType)[0]));
        if ($normalized === '') {
            return [[
                'in' => 'response',
                'name' => '',
                'pointer' => '',
                'message' => 'response has no Content-Type header',
            ]];
        }
        $definition = $content[$normalized] ?? null;
        if (!is_array($definition)) {
            return [[
                'in' => 'response',
                'name' => $normalized,
                'pointer' => '',
                'message' => "response media type '{$normalized}' is not documented",
            ]];
        }
        if (!$this->isJsonMediaType($normalized)) {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[
                'in' => 'response',
                'name' => $normalized,
                'pointer' => '',
                'message' => 'response body is not valid JSON',
            ]];
        }

        $schema = is_array($definition['schema'] ?? null) ? $definition['schema'] : null;
        $issues = [];
        foreach ($this->checker->check($decoded, $schema, false) as $issue) {
            $issues[] = [
                'in' => 'response',
                'name' => $normalized,
                'pointer' => $issue['pointer'],
                'message' => $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * Exact status key, then 'default', then the 'NXX' range form.
     *
     * @param array<int|string, mixed> $responses
     */
    private function responseKey(array $responses, int $status): int|string|null
    {
        if (array_key_exists($status, $responses)) {
            return $status;
        }
        if (array_key_exists('default', $responses)) {
            return 'default';
        }
        $range = ((string) intdiv($status, 100)) . 'XX';
        if (array_key_exists($range, $responses)) {
            return $range;
        }

        return null;
    }

    /**
     * Templates that carry the request method. HEAD falls back to GET —
     * the router's effective-methods parity (B2).
     *
     * @param list<GatePathMatch> $matches
     *
     * @return list<array{template: GateTemplate, params: array<string, string>, method: string, operation: array<mixed, mixed>}>
     */
    private function methodCandidates(string $method, array $matches): array
    {
        $wanted = strtolower(trim($method));
        $keys = $wanted === 'head' ? ['head', 'get'] : [$wanted];

        $candidates = [];
        foreach ($matches as $match) {
            $methods = $match['template']['methods'];
            foreach ($keys as $key) {
                $operation = $methods[$key] ?? null;
                if (is_array($operation)) {
                    $candidates[] = [
                        'template' => $match['template'],
                        'params' => $match['params'],
                        'method' => $key,
                        'operation' => $operation,
                    ];

                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * Boundary B3: path parameters of one candidate, coerced as string
     * transport. Undeclared placeholders stay unconstrained (the boot
     * validator requires declared path parameters to be required:true,
     * but does not require every placeholder to be declared).
     *
     * @param array<mixed, mixed>  $operation
     * @param array<string, string> $params
     *
     * @return list<GateIssue>
     */
    private function pathParameterIssues(array $operation, array $params): array
    {
        $issues = [];
        foreach ($this->declaredParameters($operation) as $parameter) {
            if ($parameter['in'] !== 'path') {
                continue;
            }
            $name = $parameter['name'];
            $value = $params[$name] ?? null;
            if ($value === null) {
                continue;
            }
            foreach ($this->checker->check($value, $parameter['schema'], true) as $issue) {
                $issues[] = [
                    'in' => 'path',
                    'name' => $name,
                    'pointer' => $issue['pointer'],
                    'message' => $issue['message'],
                ];
            }
        }

        return $issues;
    }

    /**
     * Boundaries B4/B5/B6: declared query/header/cookie parameters.
     *
     * @param array<mixed, mixed> $operation
     *
     * @return list<GateIssue>
     */
    private function parameterIssues(array $operation, OpenApiGateRequest $request): array
    {
        $issues = [];
        foreach ($this->declaredParameters($operation) as $parameter) {
            $in = $parameter['in'];
            if ($in === 'path') {
                continue;
            }
            $name = $parameter['name'];

            $value = match ($in) {
                'query' => $request->query[$name] ?? null,
                'header' => $request->headers[strtolower($name)] ?? null,
                'cookie' => $request->cookies[$name] ?? null,
                default => null,
            };
            if ($value === null || $value === '') {
                if ($parameter['required']) {
                    $issues[] = [
                        'in' => $in,
                        'name' => $name,
                        'pointer' => '',
                        'message' => "required {$in} parameter '{$name}' is missing",
                    ];
                }

                continue;
            }

            // B5 array style: a query parameter typed array accepts the PHP
            // repeated-key form (style=form, explode=true) or a single
            // comma-separated string (explode=false). Headers stay verbatim.
            if ($in === 'query' && is_string($value) && ($parameter['schema']['type'] ?? null) === 'array') {
                $value = explode(',', $value);
            }

            foreach ($this->checker->check($value, $parameter['schema'], true) as $issue) {
                $issues[] = [
                    'in' => $in,
                    'name' => $name,
                    'pointer' => $issue['pointer'],
                    'message' => $issue['message'],
                ];
            }
        }

        return $issues;
    }

    /**
     * B6 strict mode: query keys the operation does not declare.
     *
     * @param array<mixed, mixed> $operation
     *
     * @return list<GateIssue>
     */
    private function undeclaredQueryIssues(array $operation, OpenApiGateRequest $request): array
    {
        $declared = [];
        foreach ($this->declaredParameters($operation) as $parameter) {
            if ($parameter['in'] === 'query') {
                $declared[$parameter['name']] = true;
            }
        }

        $issues = [];
        foreach ($request->query as $key => $_value) {
            if (!isset($declared[$key])) {
                $issues[] = [
                    'in' => 'query',
                    'name' => $key,
                    'pointer' => '',
                    'message' => "undeclared query parameter '{$key}' is not allowed",
                ];
            }
        }

        return $issues;
    }

    /**
     * Normalized declared-parameter list: name non-empty, in one of the four
     * locations, required flag resolved, schema normalized to an array
     * (absent schema = presence-only contract).
     *
     * @param array<mixed, mixed> $operation
     *
     * @return list<array{name: string, in: string, required: bool, schema: array<mixed, mixed>}>
     */
    private function declaredParameters(array $operation): array
    {
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        $declared = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $name = $parameter['name'] ?? null;
            $in = $parameter['in'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($in)) {
                continue;
            }
            if (!in_array($in, ['query', 'header', 'path', 'cookie'], true)) {
                continue;
            }
            $schema = $parameter['schema'] ?? null;

            $declared[] = [
                'name' => $name,
                'in' => $in,
                'required' => ($parameter['required'] ?? false) === true || $in === 'path',
                'schema' => is_array($schema) ? $schema : [],
            ];
        }

        return $declared;
    }

    /**
     * Boundary B10. Null = satisfied (or no security contract at all).
     *
     * @param array<mixed, mixed> $operation
     */
    private function securityVerdict(array $operation, OpenApiGateRequest $request): ?OpenApiGateVerdict
    {
        $requirements = $this->effectiveRequirements($operation);
        if ($requirements === []) {
            return null;
        }

        $identity = $request->authenticatedIdentity();
        $scopes = $request->grantedScopes();

        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                // Malformed requirement: the boot validator's shape check
                // (OpenApiSecurityValidator) already refused these documents.
                continue;
            }
            if ($requirement === []) {
                // An empty requirement object is satisfied by definition.
                return null;
            }

            $unsatisfied = [];
            foreach ($requirement as $schemeName => $neededScopes) {
                if (!is_string($schemeName)) {
                    continue;
                }
                $scheme = $this->index->securitySchemes[$schemeName] ?? null;
                $satisfied = $identity !== null
                    || (is_array($scheme) && $this->schemeEvidence($scheme, $request));

                // Scope checks only run when the application exposes grants;
                // otherwise the decision is delegated to the authorization
                // layer (parity matrix B10, documented pass-through).
                if ($satisfied && $scopes !== null && is_array($neededScopes) && $neededScopes !== []) {
                    foreach ($neededScopes as $scope) {
                        if (is_string($scope) && !in_array($scope, $scopes, true)) {
                            $satisfied = false;

                            break;
                        }
                    }
                }
                if (!$satisfied) {
                    $unsatisfied[] = $schemeName;
                }
            }
            if ($unsatisfied === []) {
                return null;
            }
        }

        $status = $identity !== null ? 403 : 401;
        $first = is_array($requirements[0] ?? null) ? $requirements[0] : [];
        $names = [];
        foreach ($first as $schemeName => $_scopes) {
            if (is_string($schemeName)) {
                $names[] = $schemeName;
            }
        }

        $issues = [];
        foreach ($names as $schemeName) {
            $issues[] = [
                'in' => 'security',
                'name' => $schemeName,
                'pointer' => '',
                'message' => "security scheme '{$schemeName}' is not satisfied",
            ];
        }

        return OpenApiGateVerdict::rejected(
            $status,
            'The security requirement (' . implode(', ', $names) . ') is not satisfied.',
            $issues,
        );
    }

    /**
     * @param array<mixed, mixed> $operation
     *
     * @return array<mixed, mixed>
     */
    private function effectiveRequirements(array $operation): array
    {
        if (array_key_exists('security', $operation)) {
            $security = $operation['security'];

            return is_array($security) ? $security : [];
        }
        if ($this->index->specSecurity !== null) {
            return $this->index->specSecurity;
        }

        return [];
    }

    /**
     * Cheap presence-of-evidence check — never a credential verification.
     *
     * @param array<mixed, mixed> $scheme
     */
    private function schemeEvidence(array $scheme, OpenApiGateRequest $request): bool
    {
        $type = $scheme['type'] ?? null;

        if ($type === 'http') {
            $authorization = $request->headers['authorization'] ?? '';
            if ($authorization === '') {
                return false;
            }
            $name = is_string($scheme['scheme'] ?? null) ? strtolower(trim($scheme['scheme'])) : '';

            return match ($name) {
                'bearer', 'basic', 'digest' => str_starts_with(strtolower($authorization), $name . ' '),
                default => true,
            };
        }

        if ($type === 'apiKey') {
            $in = $scheme['in'] ?? null;
            $name = is_string($scheme['name'] ?? null) ? $scheme['name'] : '';
            if ($name === '') {
                return false;
            }

            return match ($in) {
                'header' => ($request->headers[strtolower($name)] ?? '') !== '',
                'query' => ($request->query[$name] ?? null) !== null && $request->query[$name] !== '',
                'cookie' => ($request->cookies[$name] ?? null) !== null && $request->cookies[$name] !== '',
                default => false,
            };
        }

        // oauth2 / openIdConnect / mutualTLS have no cheap evidence path —
        // only a verified identity attribute satisfies them (documented).
        return false;
    }

    /**
     * Boundaries B7/B8/B9. Null = no rejection. The lazy body provider is
     * invoked ONLY here — operations without a requestBody never read the
     * stream, and operations with one only read it after security passed.
     *
     * @param array<mixed, mixed> $operation
     *
     * @return null|GateBodyRejection
     */
    private function bodyRejection(array $operation, OpenApiGateRequest $request): ?array
    {
        $requestBody = $operation['requestBody'] ?? null;
        if (!is_array($requestBody)) {
            return null;
        }
        $content = $requestBody['content'] ?? null;
        if (!is_array($content) || $content === []) {
            // A body contract without media types has nothing enforceable.
            return null;
        }

        $raw = $request->bodyContents();
        if ($raw === null || trim($raw) === '') {
            if (($requestBody['required'] ?? false) === true) {
                return [
                    'status' => 400,
                    'detail' => 'The request body is required.',
                    'issues' => [
                        ['in' => 'body', 'name' => '', 'pointer' => '', 'message' => 'request body is required'],
                    ],
                    'extensions' => [],
                ];
            }

            return null;
        }

        $mediaType = strtolower(trim(explode(';', $request->contentType)[0]));
        if ($mediaType === '' || !isset($content[$mediaType])) {
            $supported = [];
            foreach ($content as $key => $_definition) {
                if (is_string($key)) {
                    $supported[] = $key;
                }
            }

            return [
                'status' => 415,
                'detail' => $mediaType === ''
                    ? 'A Content-Type header is required for this operation.'
                    : "Media type '{$mediaType}' is not offered by this operation.",
                'issues' => [
                    [
                        'in' => 'body',
                        'name' => $mediaType,
                        'pointer' => '',
                        'message' => "media type '{$mediaType}' is not documented",
                    ],
                ],
                'extensions' => ['supported' => $supported],
            ];
        }

        if (!$this->isJsonMediaType($mediaType)) {
            // Declared non-JSON media type: no schema check (non-goal #3).
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'status' => 400,
                'detail' => 'The request body is not valid JSON.',
                'issues' => [
                    ['in' => 'body', 'name' => $mediaType, 'pointer' => '', 'message' => 'malformed JSON body'],
                ],
                'extensions' => [],
            ];
        }

        $schema = is_array($content[$mediaType] ?? null) ? ($content[$mediaType]['schema'] ?? null) : null;
        $schema = is_array($schema) ? $schema : null;
        $issues = [];
        foreach ($this->checker->check($decoded, $schema, false) as $issue) {
            $issues[] = [
                'in' => 'body',
                'name' => $mediaType,
                'pointer' => $issue['pointer'],
                'message' => $issue['message'],
            ];
        }
        if ($issues !== []) {
            return [
                'status' => 400,
                'detail' => 'The request body violates the documented schema.',
                'issues' => $issues,
                'extensions' => [],
            ];
        }

        return null;
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
