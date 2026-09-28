<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Minimal deterministic YAML emitter for specification-shaped data
 * (nested string-keyed maps, lists, scalars). Deliberately dependency
 * free — symfony/yaml is not a runtime dependency of the framework.
 *
 * Emission rules:
 *   - two-space indentation, no anchors/tags/flow collections;
 *   - scalars are quoted only when required (reserved words, numeric or
 *     boolean look-alikes, leading/trailing whitespace, YAML specials,
 *     newlines); multi-line strings use double-quoted escapes;
 *   - empty maps/lists emit {} / [] inline so documents stay parseable.
 */
final class YamlSpecificationSerializer
{
    private const int MAX_DEPTH = 512;

    /**
     * @param array<string, mixed> $spec
     */
    public function serialize(array $spec): string
    {
        $lines = $this->emitArray($spec, 0);
        $output = implode("\n", $lines);

        return $output === '' ? "{ }\n" : $output . "\n";
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private function emitArray(array $value, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SpecificationException('YAML serialization exceeded the maximum nesting depth of ' . self::MAX_DEPTH . '.');
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $keyLine = $this->emitKey($key) . ':';
            if (is_array($item)) {
                if ($item === []) {
                    $lines[] = $keyLine . ' []';

                    continue;
                }
                if (array_is_list($item)) {
                    $lines[] = $keyLine;
                    foreach ($item as $listItem) {
                        $lines = [...$lines, ...array_map(
                            static fn (string $line): string => '  ' . $line,
                            $this->emitListItem($listItem, $depth),
                        )];
                    }

                    continue;
                }
                $lines[] = $keyLine;
                foreach ($this->emitArray($item, $depth + 1) as $child) {
                    $lines[] = '  ' . $child;
                }

                continue;
            }
            $lines[] = $keyLine . ' ' . $this->emitScalar($item);
        }

        return $lines;
    }

    /** @return list<string> */
    private function emitListItem(mixed $item, int $depth): array
    {
        if (is_array($item)) {
            if ($item === []) {
                return ['- []'];
            }
            $inner = array_is_list($item)
                ? $this->emitListValue($item, $depth + 1)
                : $this->emitArray($item, $depth + 1);
            $first = array_shift($inner);
            $lines = ['- ' . $first];
            foreach ($inner as $child) {
                $lines[] = '  ' . $child;
            }

            return $lines;
        }

        return ['- ' . $this->emitScalar($item)];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private function emitListValue(array $value, int $depth): array
    {
        return $this->emitArray($value, $depth);
    }

    private function emitKey(int|string $key): string
    {
        return $this->emitScalar((string) $key);
    }

    private function emitScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new SpecificationException('YAML emitter supports scalars and arrays only; got ' . get_debug_type($value) . '.');
        }

        return $this->quoteString($value);
    }

    private function quoteString(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        if ($this->needsQuoting($value)) {
            return '"' . addcslashes($value, "\"\\\t\r\n") . '"';
        }

        return $value;
    }

    private function needsQuoting(string $value): bool
    {
        // SonarCloud php:S5850 (issue #176): group the anchored
        // alternatives explicitly so each anchor binds to one branch.
        if (preg_match('/^(?:\s)|\s$/', $value) === 1) {
            return true;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return true;
        }
        if (is_numeric($value)) {
            return true;
        }
        $lower = strtolower($value);
        if (in_array($lower, ['null', '~', 'true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n', 'nan', '.inf', '-.inf'], true)) {
            return true;
        }

        return preg_match('/^[\[\]{}#&*!|>\'%@`,:\-]/', $value) === 1 || str_contains($value, ': ') || str_contains($value, ' #') || str_contains($value, '"');
    }
}
