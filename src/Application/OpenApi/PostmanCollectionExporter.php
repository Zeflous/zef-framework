<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Converts an OpenAPI document into a Postman Collection v2.1 payload.
 *
 * Pure deterministic mapping (same spec in, same collection out):
 *   - one folder per path, one request per operation;
 *   - path parameters become :variables, query parameters become the url
 *     query list;
 *   - JSON request bodies embed the schema example/default when present;
 *   - security schemes map onto Postman's collection-level auth section.
 */
final class PostmanCollectionExporter
{
    private const string POSTMAN_SCHEMA = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    /** Media types (in preference order) whose schema example is exported. */
    private const array MEDIA_TYPES = [
        'application/json',
        'application/problem+json',
        'text/plain',
        'application/x-www-form-urlencoded',
        'multipart/form-data',
    ];

    /**
     * @param array<string, mixed> $spec built OpenAPI document
     *
     * @return array{info: array<string, mixed>, item: list<array<string, mixed>>, auth?: array<string, mixed>}
     */
    public function export(array $spec): array
    {
        $info = is_array($spec['info'] ?? null) ? $spec['info'] : [];
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $components = is_array($spec['components'] ?? null) ? $spec['components'] : [];
        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];

        $collection = [
            'info' => [
                'name' => is_string($info['title'] ?? null) ? $info['title'] : 'ZEF API',
                'schema' => self::POSTMAN_SCHEMA,
                'description' => is_string($info['description'] ?? null) ? $info['description'] : '',
            ],
            'item' => $this->buildItems($paths),
        ];
        if (is_string($info['version'] ?? null) && $info['version'] !== '') {
            $collection['info']['version'] = $info['version'];
        }

        $auth = $this->buildAuth($securitySchemes);
        if ($auth !== null) {
            $collection['auth'] = $auth;
        }

        return $collection;
    }

    /**
     * One folder per path that has at least one valid operation.
     *
     * @param array<array-key, mixed> $paths
     *
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $paths): array
    {
        $items = [];
        foreach ($paths as $path => $operations) {
            if (!is_string($path) || !is_array($operations)) {
                continue;
            }
            $requests = $this->buildRequests($path, $operations);
            if ($requests !== []) {
                $items[] = ['name' => $path, 'item' => $requests];
            }
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $operations
     *
     * @return list<array<string, mixed>>
     */
    private function buildRequests(string $path, array $operations): array
    {
        $requests = [];
        foreach ($operations as $method => $operation) {
            if (!is_string($method) || !is_array($operation)) {
                continue;
            }
            $requests[] = $this->buildRequest($method, $path, $operation);
        }

        return $requests;
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function buildRequest(string $method, string $path, array $operation): array
    {
        $request = [
            'name' => is_string($operation['operationId'] ?? null)
                ? $operation['operationId']
                : strtoupper($method) . ' ' . $path,
            'request' => [
                'method' => strtoupper($method),
                'header' => [],
                'url' => [
                    'raw' => '{{baseUrl}}' . $path,
                    'host' => ['{{baseUrl}}'],
                    'path' => $this->pathSegments($path),
                    'query' => $this->queryEntries($operation),
                ],
                'description' => $this->requestDescription($operation),
            ],
            'response' => [],
        ];

        $body = $this->buildBody($operation);
        if ($body !== null) {
            $request['request']['body'] = $body;
        }

        return $request;
    }

    /**
     * Path segments with `{param}` placeholders rewritten to Postman
     * `:param` variables.
     *
     * @return list<mixed>
     */
    private function pathSegments(string $path): array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segments[] = $segment === ''
                ? $segment
                : preg_replace('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', ':$1', $segment);
        }

        return $segments;
    }

    /**
     * Query-parameter entries (key with empty value, description when set).
     *
     * @param array<array-key, mixed> $operation
     *
     * @return list<array<string, mixed>>
     */
    private function queryEntries(array $operation): array
    {
        $query = [];
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($parameters as $parameter) {
            if (
                !is_array($parameter)
                || ($parameter['in'] ?? '') !== 'query'
                || !is_string($parameter['name'] ?? null)
            ) {
                continue;
            }
            $entry = ['key' => $parameter['name'], 'value' => ''];
            if (is_string($parameter['description'] ?? null) && $parameter['description'] !== '') {
                $entry['description'] = $parameter['description'];
            }
            $query[] = $entry;
        }

        return $query;
    }

    /**
     * Summary and description joined with a blank line (summary first).
     *
     * @param array<array-key, mixed> $operation
     */
    private function requestDescription(array $operation): string
    {
        $description = '';
        if (is_string($operation['summary'] ?? null) && $operation['summary'] !== '') {
            $description = $operation['summary'];
        }
        if (is_string($operation['description'] ?? null) && $operation['description'] !== '') {
            $description = $description === ''
                ? $operation['description']
                : $description . "\n\n" . $operation['description'];
        }

        return $description;
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @return null|array<string, mixed>
     */
    private function buildBody(array $operation): ?array
    {
        $requestBody = is_array($operation['requestBody'] ?? null) ? $operation['requestBody'] : null;
        if ($requestBody === null) {
            return null;
        }
        $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];
        foreach (self::MEDIA_TYPES as $mediaType) {
            if (!isset($content[$mediaType]) || !is_array($content[$mediaType])) {
                continue;
            }
            $schema = is_array($content[$mediaType]['schema'] ?? null) ? $content[$mediaType]['schema'] : [];
            $raw = json_encode(
                $this->exampleFromSchema($schema),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            return [
                'mode' => 'raw',
                'raw' => $raw,
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        return ['mode' => 'raw', 'raw' => '{}', 'options' => ['raw' => ['language' => 'json']]];
    }

    /**
     * Build a deterministic minimal example value from a schema array.
     *
     * @param array<array-key, mixed> $schema
     */
    private function exampleFromSchema(array $schema): mixed
    {
        $literal = $this->literalExample($schema);
        if ($literal !== null) {
            return $literal;
        }
        $type = is_string($schema['type'] ?? null) ? $schema['type'] : 'object';

        return match ($type) {
            'object' => $this->objectExample($schema),
            'array' => $this->arrayExample($schema),
            'integer', 'number' => isset($schema['minimum']) && is_numeric($schema['minimum'])
                ? $schema['minimum']
                : 1,
            'boolean' => true,
            default => $this->stringExample($schema),
        };
    }

    /**
     * Explicit example/default/first-enum literal, or null when the schema
     * has none (null-valued entries count as absent, mirroring isset()).
     *
     * @param array<array-key, mixed> $schema
     */
    private function literalExample(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }
        if (isset($schema['default'])) {
            return $schema['default'];
        }
        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && isset($enum[0])) {
            return $enum[0];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return \stdClass|array<string, mixed>
     */
    private function objectExample(array $schema): \stdClass|array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $out = [];
        foreach ($properties as $name => $property) {
            if (is_string($name) && is_array($property)) {
                $out[$name] = $this->exampleFromSchema($property);
            }
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<mixed>
     */
    private function arrayExample(array $schema): array
    {
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

        return [$this->exampleFromSchema($items)];
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function stringExample(array $schema): string
    {
        return ($schema['format'] ?? null) === 'uuid'
            ? '00000000-0000-4000-8000-000000000000'
            : 'string';
    }

    /**
     * First recognized security scheme as Postman collection-level auth.
     *
     * @param array<array-key, mixed> $securitySchemes
     *
     * @return null|array<string, mixed>
     */
    private function buildAuth(array $securitySchemes): ?array
    {
        foreach ($securitySchemes as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $auth = $this->authForScheme($scheme);
            if ($auth !== null) {
                return $auth;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $scheme
     *
     * @return null|array<string, mixed>
     */
    private function authForScheme(array $scheme): ?array
    {
        $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : '';
        if ($type === 'http') {
            return $this->httpAuth($scheme);
        }
        if ($type === 'apiKey') {
            return $this->apiKeyAuth($scheme);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $scheme
     *
     * @return null|array<string, mixed>
     */
    private function httpAuth(array $scheme): ?array
    {
        $httpScheme = is_string($scheme['scheme'] ?? null) ? $scheme['scheme'] : '';
        if ($httpScheme === 'bearer') {
            return [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '<bearer-token>', 'type' => 'string']],
            ];
        }
        if ($httpScheme === 'basic') {
            return [
                'type' => 'basic',
                'basic' => [
                    ['key' => 'username', 'value' => '<username>', 'type' => 'string'],
                    ['key' => 'password', 'value' => '<password>', 'type' => 'string'],
                ],
            ];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $scheme
     *
     * @return array<string, mixed>
     */
    private function apiKeyAuth(array $scheme): array
    {
        $in = is_string($scheme['in'] ?? null) ? $scheme['in'] : 'header';

        return [
            'type' => 'apikey',
            'apikey' => [
                ['key' => 'in', 'value' => $in, 'type' => 'string'],
                ['key' => 'key', 'value' => '<api-key-name>', 'type' => 'string'],
                ['key' => 'value', 'value' => '<api-key-value>', 'type' => 'string'],
            ],
        ];
    }
}
