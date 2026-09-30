<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Boundaries B3–B6 of the OpenAPI runtime gate: the declared parameter
 * contract (path/query/header/cookie), template-choice path validation,
 * and the strictQuery mode. Extracted from
 * {@see OpenApiRequestGate} for the class-size budget (php:S2042);
 * behaviour, messages and precedence are carried over verbatim.
 *
 * @phpstan-type GateIssue array{in: string, name: string, pointer: string, message: string}
 * @phpstan-type GateDeclaredParameter array{name: string, in: string, required: bool, schema: array<mixed, mixed>}
 */
final readonly class OpenApiParameterContract
{
    public function __construct(
        private OpenApiSchemaChecker $checker,
    ) {}

    /**
     * Boundary B3: path parameters of one candidate, coerced as string
     * transport. Undeclared placeholders stay unconstrained (the boot
     * validator requires declared path parameters to be required:true,
     * but does not require every placeholder to be declared).
     *
     * @param array<mixed, mixed>   $operation
     * @param array<string, string> $params
     *
     * @return list<GateIssue>
     */
    public function pathIssues(array $operation, array $params): array
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
     * Boundaries B4/B5/B6: declared query/header/cookie parameters.
     *
     * @param array<mixed, mixed> $operation
     *
     * @return list<GateIssue>
     */
    public function parameterIssues(array $operation, OpenApiGateRequest $request): array
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
     * B6 strict mode: query keys the operation does not declare.
     *
     * @param array<mixed, mixed> $operation
     *
     * @return list<GateIssue>
     */
    public function undeclaredQueryIssues(array $operation, OpenApiGateRequest $request): array
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
     * @return list<GateDeclaredParameter>
     */
    public function declaredParameters(array $operation): array
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

    /**
     * @param GateDeclaredParameter  $parameter
     * @param array<string, string>  $params
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
     * @param GateDeclaredParameter $parameter
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
     * @param GateDeclaredParameter $parameter
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
}
