<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Structural validation of an assembled OpenAPI document.
 *
 * This is NOT a full JSON-Schema validator: it checks the invariants the
 * framework itself relies on when serving/exporting documents (valid
 * version string, present info, resolvable refs, unique operationIds,
 * non-empty responses, sane parameters and security schemes) and returns
 * deterministic, human-readable error strings.
 */
final class OpenApiSpecValidator
{
    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<string> empty list when the document is valid
     */
    public function validate(array $spec): array
    {
        $errors = [];
        $this->validateRoot($spec, $errors);
        $components = $this->components($spec);
        $this->validatePaths($spec, $errors);
        $this->validateComponentSchemas($components, $errors);
        $this->walkRefs($spec, $this->schemaNames($components), $errors);
        $this->validateSecuritySchemes($components, $errors);

        return $errors;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string> $errors
     */
    private function validateRoot(array $spec, array &$errors): void
    {
        $version = $spec['openapi'] ?? null;
        if (!is_string($version) || preg_match('/^3\.\d+\.\d+$/', $version) !== 1) {
            $errors[] = 'Field "openapi" must be a semver string like "3.1.0".';
        }
        $info = $spec['info'] ?? null;
        if (!is_array($info)) {
            $errors[] = 'Field "info" must be an object.';
            $info = [];
        }
        $title = $info['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $errors[] = 'Field "info.title" must be a non-empty string.';
        }
        $infoVersion = $info['version'] ?? null;
        if (!is_string($infoVersion) || trim($infoVersion) === '') {
            $errors[] = 'Field "info.version" must be a non-empty string.';
        }
        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            $errors[] = 'Field "paths" must be an object.';
        }
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<mixed, mixed> the raw "components" object, unfiltered
     */
    private function components(array $spec): array
    {
        return is_array($spec['components'] ?? null) ? $spec['components'] : [];
    }

    /**
     * @param array<mixed, mixed> $components
     *
     * @return array<string, true>
     */
    private function schemaNames(array $components): array
    {
        $names = [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        foreach ($schemas as $name => $schema) {
            if (is_string($name)) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string> $errors
     */
    private function validatePaths(array $spec, array &$errors): void
    {
        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            return;
        }
        $operationIds = [];
        ksort($paths, SORT_STRING);
        foreach ($paths as $path => $operations) {
            $this->validatePathEntry($path, $operations, $operationIds, $errors);
        }
    }

    /**
     * @param array<string, string> $operationIds
     * @param list<string> $errors
     */
    private function validatePathEntry(mixed $path, mixed $operations, array &$operationIds, array &$errors): void
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            $errors[] = "Path key '" . $this->stringify($path) . "' must be a non-empty string beginning with '/'.";

            return;
        }
        if (!is_array($operations)) {
            $errors[] = "Path '{$path}' must map to an object of operations.";

            return;
        }
        foreach ($operations as $method => $operation) {
            $this->validateOperation($path, $method, $operation, $operationIds, $errors);
        }
    }

    /**
     * @param array<string, string> $operationIds
     * @param list<string> $errors
     */
    private function validateOperation(
        string $path,
        mixed $method,
        mixed $operation,
        array &$operationIds,
        array &$errors,
    ): void {
        if (!is_string($method) || !in_array($method, self::METHODS, true)) {
            $errors[] = "Path '{$path}' contains an unknown HTTP method '" . $this->stringify($method) . "'.";

            return;
        }
        $where = "[{$method}] {$path}";
        if (!is_array($operation)) {
            $errors[] = "Operation {$where} must be an object.";

            return;
        }
        $this->validateOperationId($where, $operation, $operationIds, $errors);
        $this->validateResponses($where, $operation, $errors);
        $this->validateParameters($where, $operation, $path, $errors);
        $this->validateOperationSecurity($where, $operation, $errors);
    }

    /**
     * @param array<mixed, mixed> $operation
     * @param array<string, string> $operationIds
     * @param list<string> $errors
     */
    private function validateOperationId(string $where, array $operation, array &$operationIds, array &$errors): void
    {
        $operationId = $operation['operationId'] ?? null;
        if (!is_string($operationId) || trim($operationId) === '') {
            $errors[] = "Operation {$where} must define a non-empty operationId.";
        } elseif (isset($operationIds[$operationId])) {
            $errors[] = "Duplicate operationId '{$operationId}' (also used by {$operationIds[$operationId]}).";
        } else {
            $operationIds[$operationId] = $where;
        }
    }

    /**
     * @param array<mixed, mixed> $operation
     * @param list<string> $errors
     */
    private function validateResponses(string $where, array $operation, array &$errors): void
    {
        $responses = $operation['responses'] ?? null;
        if (!is_array($responses) || $responses === []) {
            $errors[] = "Operation {$where} must define at least one response.";

            return;
        }
        foreach ($responses as $status => $response) {
            $description = is_array($response) ? ($response['description'] ?? null) : null;
            if (!is_string($description) || $description === '') {
                $statusLabel = $this->stringify($status);
                $errors[] = "Operation {$where} response '{$statusLabel}' must carry a non-empty description.";
            }
        }
    }

    /**
     * @param array<mixed, mixed> $operation
     * @param list<string> $errors
     */
    private function validateParameters(string $where, array $operation, string $path, array &$errors): void
    {
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || !is_string($parameter['name'] ?? null) || $parameter['name'] === '') {
                $errors[] = "Operation {$where} contains a parameter without a non-empty name.";

                continue;
            }
            $in = is_string($parameter['in'] ?? null) ? $parameter['in'] : '';
            if (!in_array($in, ['query', 'header', 'path', 'cookie'], true)) {
                $errors[] = "Operation {$where} parameter '{$parameter['name']}' has invalid location '{$in}'.";

                continue;
            }
            if (
                $in === 'path'
                && ($parameter['required'] ?? false) !== true
                && str_contains($path, '{' . $parameter['name'] . '}')
            ) {
                $errors[] = "Operation {$where} path parameter '{$parameter['name']}' must set required: true.";
            }
            // Recognised location with a consistent required flag.
        }
    }

    /**
     * @param array<mixed, mixed> $operation
     * @param list<string> $errors
     */
    private function validateOperationSecurity(string $where, array $operation, array &$errors): void
    {
        $security = is_array($operation['security'] ?? null) ? $operation['security'] : [];
        foreach ($security as $requirement) {
            if (!is_array($requirement)) {
                $errors[] = "Operation {$where} contains a malformed security requirement.";
            }
        }
    }

    /**
     * @param array<mixed, mixed> $components
     * @param list<string> $errors
     */
    private function validateComponentSchemas(array $components, array &$errors): void
    {
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        foreach ($schemas as $name => $schema) {
            if (!is_string($name) || $name === '') {
                $errors[] = 'Component schema names must be non-empty strings.';

                continue;
            }
            if (!is_array($schema)) {
                $errors[] = "Component schema '{$name}' must be an object.";
            }
        }
    }

    /**
     * @param array<mixed, mixed> $components
     * @param list<string> $errors
     */
    private function validateSecuritySchemes(array $components, array &$errors): void
    {
        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];
        foreach ($securitySchemes as $name => $scheme) {
            $this->validateSecurityScheme($name, $scheme, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateSecurityScheme(mixed $name, mixed $scheme, array &$errors): void
    {
        if (!is_string($name) || $name === '') {
            $errors[] = 'Security scheme names must be non-empty strings.';

            return;
        }
        if (!is_array($scheme) || !is_string($scheme['type'] ?? null)) {
            $errors[] = "Security scheme '{$name}' must be an object with a type.";

            return;
        }
        $type = $scheme['type'];
        if ($type === 'http' && (!is_string($scheme['scheme'] ?? null) || $scheme['scheme'] === '')) {
            $errors[] = "Security scheme '{$name}' of type http must define a scheme.";
        }
        if ($type === 'apiKey' && !in_array($scheme['in'] ?? null, ['query', 'header', 'cookie'], true)) {
            $errors[] = "Security scheme '{$name}' of type apiKey must define in: query|header|cookie.";
        }
        if ($type === 'openIdConnect' && !is_string($scheme['openIdConnectUrl'] ?? null)) {
            $errors[] = "Security scheme '{$name}' of type openIdConnect must define openIdConnectUrl.";
        }
    }

    /**
     * Collect every $ref that cannot be resolved against component schemas.
     *
     * @param array<mixed, mixed> $node
     * @param array<string, true> $schemaNames
     * @param list<string> $errors
     */
    private function walkRefs(array $node, array $schemaNames, array &$errors, int $depth = 0): void
    {
        if ($depth > 256) {
            return;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $ref = $value['$ref'] ?? null;
                if (is_string($ref) && preg_match('~^#/components/schemas/([^/]+)$~', $ref, $matches) === 1) {
                    if (!isset($schemaNames[$matches[1]])) {
                        $errors[] = "Unresolvable \$ref '{$ref}' (missing from components.schemas).";
                    }

                    continue;
                }
                $this->walkRefs($value, $schemaNames, $errors, $depth + 1);
            }
        }
    }

    private function stringify(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
