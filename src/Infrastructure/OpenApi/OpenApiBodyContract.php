<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Boundaries B7/B8/B9: the request-body contract of the OpenAPI runtime
 * gate. The lazy body provider is invoked ONLY here — operations without
 * a requestBody never read the stream, and operations with one only read
 * it after security passed (the engine calls this class second).
 *
 * Rejections carry an `unsupported` flag for the 415 case; the 400-class
 * outcomes merge into the engine's parameter issues.
 *
 * Extracted from {@see OpenApiRequestGate} for the class-size budget
 * (php:S2042); behaviour, messages and precedence are carried over
 * verbatim.
 *
 * @phpstan-type GateIssue array{in: string, name: string, pointer: string, message: string}
 * @phpstan-type GateBodyRejection array{
 *     unsupported: bool,
 *     detail: string,
 *     issues: list<GateIssue>,
 *     extensions: array<string, mixed>,
 * }
 */
final class OpenApiBodyContract
{
    public function __construct(
        private readonly OpenApiSchemaChecker $checker,
    ) {}

    /**
     * Null = no rejection.
     *
     * @param array<mixed, mixed> $operation
     *
     * @return null|GateBodyRejection
     */
    public function rejection(array $operation, OpenApiGateRequest $request): ?array
    {
        $requestBody = $operation['requestBody'] ?? null;
        $content = is_array($requestBody) ? ($requestBody['content'] ?? null) : null;
        if (!is_array($content) || $content === []) {
            // No body contract, or one without media types: nothing
            // enforceable (the validator does not check requestBody shape,
            // so garbage is a reachable input here).
            return null;
        }

        /** @var array<string, mixed> $content */
        $raw = $request->bodyContents();
        if ($raw === null || trim($raw) === '') {
            return $this->requiredRejection($requestBody);
        }

        return $this->mediaRejection($content, $raw, $request);
    }

    /**
     * The JSON media-type family, shared with the response contract.
     */
    public static function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /**
     * @param array<mixed, mixed> $requestBody
     *
     * @return null|GateBodyRejection
     */
    private function requiredRejection(array $requestBody): ?array
    {
        if (($requestBody['required'] ?? false) !== true) {
            return null;
        }

        return [
            'unsupported' => false,
            'detail' => 'The request body is required.',
            'issues' => [
                ['in' => 'body', 'name' => '', 'pointer' => '', 'message' => 'request body is required'],
            ],
            'extensions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return null|GateBodyRejection
     */
    private function mediaRejection(array $content, string $raw, OpenApiGateRequest $request): ?array
    {
        $mediaType = strtolower(trim(explode(';', $request->contentType)[0]));
        if ($mediaType === '' || !isset($content[$mediaType])) {
            return $this->unsupportedRejection($content, $mediaType);
        }
        if (!self::isJsonMediaType($mediaType)) {
            // Declared non-JSON media type: no schema check (non-goal #3).
            return null;
        }

        return $this->jsonRejection($content[$mediaType] ?? null, $mediaType, $raw);
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return GateBodyRejection
     */
    private function unsupportedRejection(array $content, string $mediaType): array
    {
        $supported = [];
        foreach ($content as $key => $_definition) {
            if (is_string($key)) {
                $supported[] = $key;
            }
        }

        return [
            'unsupported' => true,
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

    /**
     * @param mixed $definition the media-type entry (garbage-tolerant)
     *
     * @return null|GateBodyRejection
     */
    private function jsonRejection(mixed $definition, string $mediaType, string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'unsupported' => false,
                'detail' => 'The request body is not valid JSON.',
                'issues' => [
                    ['in' => 'body', 'name' => $mediaType, 'pointer' => '', 'message' => 'malformed JSON body'],
                ],
                'extensions' => [],
            ];
        }

        $schema = is_array($definition) ? ($definition['schema'] ?? null) : null;
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
        if ($issues === []) {
            return null;
        }

        return [
            'unsupported' => false,
            'detail' => 'The request body violates the documented schema.',
            'issues' => $issues,
            'extensions' => [],
        ];
    }
}
