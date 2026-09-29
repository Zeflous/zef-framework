<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Derives OpenAPI path Parameter objects from a ZEF route pattern and its
 * optional Router segment array. Constraint names map to schema shapes
 * (int/uint/alpha/slug/uuid/hex); unknown or custom constraints degrade
 * to unconstrained strings. Ported from the former RouteSpecExtractor
 * pathParameters() cluster.
 */
final class RoutePathParameterFactory
{
    /**
     * Default router-constraint name => schema shape.
     */
    private const array CONSTRAINT_SCHEMAS = [
        'int' => ['type' => 'integer'],
        'uint' => ['type' => 'integer', 'minimum' => 1],
        'alpha' => ['type' => 'string', 'pattern' => '^[a-zA-Z]+$'],
        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
        'uuid' => ['type' => 'string', 'format' => 'uuid'],
        'hex' => ['type' => 'string', 'pattern' => '^[0-9a-f]+$'],
    ];

    /**
     * @param mixed $segments Router segment arrays (or null to parse pattern)
     *
     * @return list<Parameter>
     */
    public function for(string $pattern, mixed $segments): array
    {
        $names = $this->segmentNames($segments);
        if ($names === []) {
            // segments absent/empty (fixture arrays, edge tooling): parse the
            // wire pattern directly. Duplicates are preserved verbatim — the
            // Router rejects them, but the extractor stays garbage-tolerant.
            $names = $this->patternNames($pattern);
        }

        $parameters = [];
        foreach ($names as [$paramName, $constraint]) {
            $parameters[] = new Parameter(
                name: $paramName,
                in: ParameterLocation::Path,
                schema: $this->constraintSchema($constraint),
                description: $constraint !== null ? "Path parameter constrained by '{$constraint}'." : '',
            );
        }

        return $parameters;
    }

    /**
     * @return list<array{string, ?string}>
     */
    private function segmentNames(mixed $segments): array
    {
        $names = [];
        if (!is_array($segments)) {
            return $names;
        }
        foreach ($segments as $segment) {
            if (
                is_array($segment)
                && ($segment['dynamic'] ?? false) === true
                && isset($segment['name'])
                && is_string($segment['name'])
            ) {
                $constraint = is_string($segment['constraint'] ?? null) ? $segment['constraint'] : null;
                $names[] = [$segment['name'], $constraint];
            }
        }

        return $names;
    }

    /**
     * @return list<array{string, ?string}>
     */
    private function patternNames(string $pattern): array
    {
        $names = [];
        $regex = '/\{([A-Za-z_]\w*)(?::([A-Za-z_]\w*))?\}/';
        if (preg_match_all($regex, $pattern, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $names[] = [$match[1], $match[2] ?? null];
            }
        }

        return $names;
    }

    private function constraintSchema(?string $constraint): ?Schema
    {
        if ($constraint === null || !isset(self::CONSTRAINT_SCHEMAS[$constraint])) {
            return null;
        }
        $shape = self::CONSTRAINT_SCHEMAS[$constraint];

        return new Schema(
            type: SchemaType::from($shape['type']),
            format: $shape['format'] ?? null,
            pattern: $shape['pattern'] ?? null,
            minimum: $shape['minimum'] ?? null,
        );
    }
}
