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
 *
 * Example synthesis ({@see PostmanSchemaExamples}) and auth mapping
 * ({@see PostmanAuthBuilder}) live in dedicated collaborators so this
 * class stays within the class-size budget.
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
        $info = $this->arrayValue($spec, 'info');
        $paths = $this->arrayValue($spec, 'paths');
        $components = $this->arrayValue($spec, 'components');
        $securitySchemes = $this->arrayValue($components, 'securitySchemes');

        $collection = [
            'info' => [
                'name' => $this->stringValue($info, 'title', 'ZEF API'),
                'schema' => self::POSTMAN_SCHEMA,
                'description' => $this->stringValue($info, 'description', ''),
            ],
            'item' => $this->buildItems($paths),
        ];
        if (is_string($info['version'] ?? null) && $info['version'] !== '') {
            $collection['info']['version'] = $info['version'];
        }

        $auth = PostmanAuthBuilder::build($securitySchemes);
        if ($auth !== null) {
            $collection['auth'] = $auth;
        }

        return $collection;
    }

    /**
     * Defensive read of a spec section: non-arrays read as empty.
     *
     * @param array<array-key, mixed> $source
     *
     * @return array<array-key, mixed>
     */
    private function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Defensive read of an info field: non-strings read as the default.
     *
     * @param array<array-key, mixed> $source
     */
    private function stringValue(array $source, string $key, string $default): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : $default;
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
            return $description === ''
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
                PostmanSchemaExamples::exampleFromSchema($schema),
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
}
