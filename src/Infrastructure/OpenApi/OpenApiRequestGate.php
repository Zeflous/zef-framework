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
 * OpenApiSecurityValidator scheme invariants the security slice relies on.
 *
 * The security/body/response slices live in dedicated collaborators
 * (class-size budget, php:S2042); this class owns selection, the
 * parameter boundaries and the admitted-operation context.
 *
 * @phpstan-type GateIssue array{in: string, name: string, pointer: string, message: string}
 * @phpstan-type GateOperationContext array{
 *     operationId: string,
 *     path: string,
 *     method: string,
 *     pathParams: array<string, string>,
 *     responses: array<mixed, mixed>,
 * }
 * @phpstan-type GateCandidate array{
 *     template: GateTemplate,
 *     params: array<string, string>,
 *     method: string,
 *     operation: array<mixed, mixed>,
 * }
 * @phpstan-type GateTemplate array{
 *     path: string,
 *     segments: list<array{dynamic: bool, name?: string, value?: string}>,
 *     methods: array<string, array<mixed, mixed>>,
 * }
 */
final readonly class OpenApiRequestGate
{
    private function __construct(
        private OpenApiGateIndex $index,
        private OpenApiSchemaChecker $checker,
        private OpenApiGateSecurity $security,
        private OpenApiBodyContract $bodyContract,
        private OpenApiResponseContract $responseContract,
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
        $checker = new OpenApiSchemaChecker($index->schemas);

        return new self(
            $index,
            $checker,
            new OpenApiGateSecurity($index),
            new OpenApiBodyContract($checker),
            new OpenApiResponseContract($checker),
            $options,
        );
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
            return $this->methodNotAllowed($matches, $request->method);
        }

        return $this->candidateVerdict($candidates, $request);
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
        return $this->responseContract->issues($responses, $status, $mediaType, $body);
    }

    /**
     * B3 template choice, then the operation's request contract.
     *
     * @param list<GateCandidate> $candidates
     */
    private function candidateVerdict(array $candidates, OpenApiGateRequest $request): OpenApiGateVerdict
    {
        $chosen = $this->chooseTemplate($candidates);
        if ($chosen === null) {
            return OpenApiGateVerdict::rejected(
                400,
                'Path parameters violate the documented schema.',
                $this->firstPathIssues($candidates),
            );
        }

        return $this->operationVerdict($chosen, $request);
    }

    /**
     * The first (sorted) template whose path parameters validate wins.
     *
     * @param list<GateCandidate> $candidates
     *
     * @return null|GateCandidate
     */
    private function chooseTemplate(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if ($this->pathParameterIssues($candidate['operation'], $candidate['params']) === []) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The deterministic first-failure report for the 400 verdict.
     *
     * @param list<GateCandidate> $candidates
     *
     * @return list<GateIssue>
     */
    private function firstPathIssues(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            $issues = $this->pathParameterIssues($candidate['operation'], $candidate['params']);
            if ($issues !== []) {
                return $issues;
            }
        }

        return [];
    }

    /**
     * B10 first (security before validation), then the parameter/body
     * contract with its 400/415 precedence.
     *
     * @param GateCandidate $chosen
     */
    private function operationVerdict(array $chosen, OpenApiGateRequest $request): OpenApiGateVerdict
    {
        $security = $this->security->verdict($chosen['operation'], $request);
        if ($security instanceof OpenApiGateVerdict) {
            return $security;
        }

        return $this->validationVerdict($chosen, $request);
    }

    /**
     * @param GateCandidate $chosen
     */
    private function validationVerdict(array $chosen, OpenApiGateRequest $request): OpenApiGateVerdict
    {
        $body = $this->bodyContract->rejection($chosen['operation'], $request);
        if ($body !== null && $body['unsupported']) {
            return OpenApiGateVerdict::rejected(415, $body['detail'], $body['issues'], [], $body['extensions']);
        }

        $parameterIssues = $this->parameterIssues($chosen['operation'], $request);
        if ($this->options->strictQuery) {
            // Undeclared-query rejections append after the declared ones.
            $parameterIssues = [...$parameterIssues, ...$this->undeclaredQueryIssues($chosen['operation'], $request)];
        }

        $issues = [...$parameterIssues, ...($body['issues'] ?? [])];
        if ($issues !== []) {
            return OpenApiGateVerdict::rejected(400, $this->rejectionDetail($parameterIssues, $body), $issues);
        }

        return OpenApiGateVerdict::admitted($this->operationContext($chosen));
    }

    /**
     * @param list<GateIssue>            $parameterIssues
     * @param null|array<string, mixed>  $body
     */
    private function rejectionDetail(array $parameterIssues, ?array $body): string
    {
        $detail = $body['detail'] ?? null;
        if ($parameterIssues === [] && is_string($detail)) {
            return $detail;
        }

        return 'The request violates the documented API contract.';
    }

    /**
     * @param GateCandidate $chosen
     *
     * @return GateOperationContext
     */
    private function operationContext(array $chosen): array
    {
        $operation = $chosen['operation'];
        $path = $chosen['template']['path'] ?? '';

        return [
            'operationId' => is_string($operation['operationId'] ?? null) ? $operation['operationId'] : '',
            'path' => is_string($path) ? $path : '',
            'method' => strtoupper($chosen['method']),
            'pathParams' => $chosen['params'],
            'responses' => is_array($operation['responses'] ?? null) ? $operation['responses'] : [],
        ];
    }

    /**
     * Templates that carry the request method. HEAD falls back to GET —
     * the router's effective-methods parity (B2).
     *
     * @param list<array{template: array<string, mixed>, params: array<string, string>}> $matches
     *
     * @return list<GateCandidate>
     */
    /**
     * @param list<array{template: GateTemplate, params: array<string, string>}> $matches
     *
     * @return list<GateCandidate>
     */
    private function methodCandidates(string $method, array $matches): array
    {
        $wanted = strtolower(trim($method));
        $keys = $wanted === 'head' ? ['head', 'get'] : [$wanted];

        $candidates = [];
        foreach ($matches as $match) {
            /** @var array<string, array<mixed, mixed>> $methods */
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
     * @param list<array{template: GateTemplate, params: array<string, string>}> $matches
     */
    private function methodNotAllowed(array $matches, string $method): OpenApiGateVerdict
    {
        /** @var list<GateTemplate> $templates */
        $templates = array_map(static fn (array $match): array => $match['template'], $matches);
        $allowed = $this->index->allowedMethods($templates);

        return OpenApiGateVerdict::rejected(
            405,
            "Method '{$method}' is not documented for this path.",
            [],
            ['Allow' => implode(', ', $allowed)],
            ['allowed' => $allowed],
        );
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
            $issues = [...$issues, ...$this->pathIssue($parameter, $params)];
        }

        return $issues;
    }

    /**
     * @param array{name: string, in: string, required: bool, schema: array<mixed, mixed>} $parameter
     * @param array<string, string> $params
     *
     * @return list<GateIssue>
     */
    private function pathIssue(array $parameter, array $params): array
    {
        $value = $params[$parameter['name']] ?? null;
        if ($value === null) {
            return [];
        }
        $issues = [];
        foreach ($this->checker->check($value, $parameter['schema'], true) as $issue) {
            $issues[] = [
                'in' => 'path',
                'name' => $parameter['name'],
                'pointer' => $issue['pointer'],
                'message' => $issue['message'],
            ];
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
            if ($parameter['in'] === 'path') {
                continue;
            }
            $issues = [...$issues, ...$this->parameterIssue($parameter, $request)];
        }

        return $issues;
    }

    /**
     * @param array{name: string, in: string, required: bool, schema: array<mixed, mixed>} $parameter
     *
     * @return list<GateIssue>
     */
    private function parameterIssue(array $parameter, OpenApiGateRequest $request): array
    {
        $in = $parameter['in'];
        $name = $parameter['name'];

        $value = match ($in) {
            'query' => $request->query[$name] ?? null,
            'header' => $request->headers[strtolower($name)] ?? null,
            'cookie' => $request->cookies[$name] ?? null,
            default => null,
        };
        if ($value === null || $value === '') {
            return $this->missingParameterIssues($parameter);
        }

        // B5 array style: a query parameter typed array accepts the PHP
        // repeated-key form (style=form, explode=true) or a single
        // comma-separated string (explode=false). Headers stay verbatim.
        if ($in === 'query' && is_string($value) && ($parameter['schema']['type'] ?? null) === 'array') {
            $value = explode(',', $value);
        }
        $issues = [];
        foreach ($this->checker->check($value, $parameter['schema'], true) as $issue) {
            $issues[] = [
                'in' => $in,
                'name' => $name,
                'pointer' => $issue['pointer'],
                'message' => $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param array{name: string, in: string, required: bool, schema: array<mixed, mixed>} $parameter
     *
     * @return list<GateIssue>
     */
    private function missingParameterIssues(array $parameter): array
    {
        if (!$parameter['required']) {
            return [];
        }

        return [[
            'in' => $parameter['in'],
            'name' => $parameter['name'],
            'pointer' => '',
            'message' => "required {$parameter['in']} parameter '{$parameter['name']}' is missing",
        ]];
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

        // B12 trust: the boot validator already refused parameters without
        // non-empty names or with unknown locations — the annotation below
        // is a phpstan assertion, not a runtime guard.
        /** @var array{name: string, in: string, required?: bool, schema?: mixed} $parameter */
        foreach ($parameters as $parameter) {
            $schema = $parameter['schema'] ?? null;

            $declared[] = [
                'name' => $parameter['name'],
                'in' => $parameter['in'],
                'required' => ($parameter['required'] ?? false) === true || $parameter['in'] === 'path',
                'schema' => is_array($schema) ? $schema : [],
            ];
        }

        return $declared;
    }
}
