<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * Boundary B11: the response contract of the matched operation — status
 * declaration (exact / default / NXX range), declared media type, and the
 * JSON body schema. Only JSON-family media types are schema-checked;
 * declared non-JSON media types pass (parity-matrix non-goal #3).
 *
 * Extracted from {@see OpenApiRequestGate} for the class-size budget
 * (php:S2042); behaviour, messages and precedence are carried over
 * verbatim.
 */
final readonly class OpenApiResponseContract
{
    public function __construct(
        private OpenApiSchemaChecker $checker,
    ) {}

    /**
     * @param array<mixed, mixed> $responses the operation's responses map
     *
     * @return list<array{in: string, name: string, pointer: string, message: string}>
     */
    public function issues(array $responses, int $status, string $mediaType, ?string $body): array
    {
        $key = $this->responseKey($responses, $status);
        if ($key === null) {
            return [$this->statusIssue($status)];
        }
        $response = $responses[$key] ?? null;
        if (!is_array($response)) {
            // Defensive: the boot-time validator requires response objects.
            return [];
        }

        return $this->responseIssues($response, $mediaType, $body);
    }

    /**
     * @return array{in: string, name: string, pointer: string, message: string}
     */
    private function statusIssue(int $status): array
    {
        return [
            'in' => 'response',
            'name' => (string) $status,
            'pointer' => '',
            'message' => "response status {$status} is not documented",
        ];
    }

    /**
     * Exact status key, then 'default', then the 'NXX' range form.
     *
     * @param array<mixed, mixed> $responses
     */
    private function responseKey(array $responses, int $status): int|string|null
    {
        if (array_key_exists($status, $responses)) {
            return $status;
        }
        foreach (['default', intdiv($status, 100) . 'XX'] as $fallback) {
            if (array_key_exists($fallback, $responses)) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $response
     *
     * @return list<array{in: string, name: string, pointer: string, message: string}>
     */
    private function responseIssues(?array $response, string $mediaType, ?string $body): array
    {
        $content = $response['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return $this->undocumentedBodyIssues($body);
        }
        if ($body === null || trim($body) === '') {
            // Nothing to validate: an empty body cannot violate a schema.
            return [];
        }

        return $this->mediaTypeIssues($content, $mediaType, $body);
    }

    /**
     * A present body with no declared content is off-contract; an absent
     * one is fine.
     *
     * @return list<array{in: string, name: string, pointer: string, message: string}>
     */
    private function undocumentedBodyIssues(?string $body): array
    {
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

    /**
     * @param array<mixed, mixed> $content
     *
     * @return list<array{in: string, name: string, pointer: string, message: string}>
     */
    private function mediaTypeIssues(array $content, string $mediaType, string $body): array
    {
        $normalized = strtolower(trim(explode(';', $mediaType)[0]));
        $issue = $this->mediaTypeIssue($content, $normalized);
        if ($issue !== null) {
            return [$issue];
        }
        if (!OpenApiBodyContract::isJsonMediaType($normalized)) {
            return [];
        }

        return $this->jsonBodyIssues($content[$normalized] ?? null, $normalized, $body);
    }

    /**
     * The media-type gate: a missing Content-Type or an undeclared media
     * type, null when the type is declared.
     *
     * @param array<mixed, mixed> $content
     *
     * @return null|array{in: string, name: string, pointer: string, message: string}
     */
    private function mediaTypeIssue(array $content, string $normalized): ?array
    {
        if ($normalized === '') {
            return [
                'in' => 'response',
                'name' => '',
                'pointer' => '',
                'message' => 'response has no Content-Type header',
            ];
        }
        if (!is_array($content[$normalized] ?? null)) {
            return [
                'in' => 'response',
                'name' => $normalized,
                'pointer' => '',
                'message' => "response media type '{$normalized}' is not documented",
            ];
        }

        return null;
    }

    /**
     * @param mixed $definition the media-type entry (garbage-tolerant)
     *
     * @return list<array{in: string, name: string, pointer: string, message: string}>
     */
    private function jsonBodyIssues(mixed $definition, string $normalized, string $body): array
    {
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

        $schema = is_array($definition) ? ($definition['schema'] ?? null) : null;
        $schema = is_array($schema) ? $schema : null;
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
}
