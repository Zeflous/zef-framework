<?php

declare(strict_types=1);

// ZEF Framework v2.33.0 — Infrastructure layer (OpenAPI runtime gate)

namespace Zef\Framework\OpenApi;

/**
 * The compiled, per-boot index of an OpenAPI document for the runtime gate.
 *
 * Built ONCE at construction (never per request): every path template is
 * pre-split into segments, every method key resolves to its operation
 * array, and the component schemas and security schemes are kept for the
 * checker and the security boundary. Templates are sorted by path key so
 * candidate order is deterministic even for hand-written documents.
 *
 * Path matching mirrors the router's semantics exactly (parity matrix B1/B2):
 * split on '/', trim leading/trailing slashes, collapse empty segments,
 * static segments compare against the RAW path text, dynamic segments are
 * rawurldecode()-d before capture. A request the index cannot match is a
 * pass-through — the router stays the owner of 404.
 *
 * @phpstan-type GateSegment array{dynamic: bool, name?: string, value?: string}
 * @phpstan-type GateTemplate array{
 *     path: string,
 *     segments: list<GateSegment>,
 *     methods: array<string, array<mixed, mixed>>,
 * }
 * @phpstan-type GatePathMatch array{template: GateTemplate, params: array<string, string>}
 */
final readonly class OpenApiGateIndex
{
    /**
     * @param list<GateTemplate>              $templates
     * @param array<mixed, mixed>             $schemas
     * @param array<mixed, mixed>             $securitySchemes
     * @param null|array<mixed, mixed>        $specSecurity
     */
    private function __construct(
        public array $templates,
        public array $schemas,
        public array $securitySchemes,
        public ?array $specSecurity,
    ) {}

    /**
     * @param array<string, mixed> $spec the built OpenAPI document
     */
    public static function fromSpec(array $spec): self
    {
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $components = is_array($spec['components'] ?? null) ? $spec['components'] : [];

        $templates = [];
        foreach ($paths as $pathKey => $operations) {
            // B12 trust: the boot validator already refused non-string path
            // keys and non-object path items — the type assertions below
            // are phpstan-only annotations, not runtime guards.
            /** @var string $pathKey */
            /** @var array<string, array<mixed, mixed>> $operations */
            $methods = [];
            foreach ($operations as $method => $operation) {
                // Method keys are guaranteed lowercase by the same
                // validator (unknown or cased keys fail construction).
                // @var string $method
                $methods[$method] = $operation;
            }
            $templates[] = [
                'path' => $pathKey,
                'segments' => self::templateSegments($pathKey),
                'methods' => $methods,
            ];
        }

        usort($templates, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        $specSecurity = $spec['security'] ?? null;

        return new self(
            $templates,
            is_array($components['schemas'] ?? null) ? $components['schemas'] : [],
            is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [],
            is_array($specSecurity) ? $specSecurity : null,
        );
    }

    /**
     * All templates whose segments structurally match the request path,
     * in sorted path order, each with its captured (decoded) parameters.
     *
     * @return list<GatePathMatch>
     */
    public function matchPath(string $path): array
    {
        $parts = self::splitPath($path);
        $matches = [];
        foreach ($this->templates as $template) {
            if (count($template['segments']) !== count($parts)) {
                continue;
            }
            $params = $this->matchSegments($template['segments'], $parts);
            if ($params !== null) {
                $matches[] = ['template' => $template, 'params' => $params];
            }
        }

        return $matches;
    }

    /**
     * The union of methods declared across the given templates, uppercased
     * and alphabetically sorted, with HEAD implied by GET — the same set
     * the router reports in MethodNotAllowedException (B2 parity).
     *
     * @param list<GateTemplate> $templates
     *
     * @return list<string>
     */
    public function allowedMethods(array $templates): array
    {
        $allowed = [];
        foreach ($templates as $template) {
            foreach ($template['methods'] as $method => $_operation) {
                $allowed[strtoupper($method)] = true;
                if ($method === 'get') {
                    $allowed['HEAD'] = true;
                }
            }
        }
        $methods = array_keys($allowed);
        sort($methods);

        return $methods;
    }

    /**
     * @param list<GateSegment> $segments
     * @param list<string>      $parts
     *
     * @return null|array<string, string>
     */
    private function matchSegments(array $segments, array $parts): ?array
    {
        $params = [];
        foreach ($segments as $index => $segment) {
            $value = $parts[$index] ?? '';
            if ($segment['dynamic']) {
                $params[$segment['name'] ?? ''] = rawurldecode($value);

                continue;
            }
            if (($segment['value'] ?? '') !== $value) {
                return null;
            }
        }

        return $params;
    }

    /**
     * Template segments from a path key: '{name}' placeholders become
     * dynamic segments; anything else (including malformed placeholders)
     * is a static literal compared verbatim.
     *
     * @return list<GateSegment>
     */
    private static function templateSegments(string $pathKey): array
    {
        $segments = [];
        foreach (self::splitPath($pathKey) as $part) {
            if (preg_match('/^\{([A-Za-z_]\w*)\}$/', $part, $matches) === 1) {
                $segments[] = ['dynamic' => true, 'name' => $matches[1]];

                continue;
            }
            $segments[] = ['dynamic' => false, 'value' => $part];
        }

        return $segments;
    }

    /**
     * Same normalization as the router's RoutePatternParser::splitPath():
     * '/' is the empty segment list, leading/trailing slashes are trimmed
     * and duplicate slashes collapse; the split happens on the RAW path so
     * a percent-encoded slash (%2F) stays inside one segment.
     *
     * @return list<string>
     */
    private static function splitPath(string $path): array
    {
        if ($path === '/') {
            return [];
        }

        return array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
    }
}
