<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Serializes specification arrays to JSON and back. Output is stable:
 * UNESCAPED_SLASHES + UNESCAPED_UNICODE so documents diff cleanly.
 */
final class JsonSpecificationSerializer
{
    /**
     * @param array<string, mixed> $spec
     */
    public function serialize(array $spec, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            return json_encode($spec, $flags, 512);
        } catch (\JsonException $exception) {
            throw new SpecificationException(
                'Failed to serialize specification to JSON: ' . $exception->getMessage(),
                $exception->getCode(),
                previous: $exception,
            );
        }
    }

    /**
     * @return array<mixed, mixed>
     */
    public function deserialize(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SpecificationException(
                'Invalid JSON specification: ' . $exception->getMessage(),
                $exception->getCode(),
                previous: $exception,
            );
        }
        if (!is_array($decoded)) {
            throw new SpecificationException('A specification document must deserialize to an object/array.');
        }

        return $decoded;
    }
}
