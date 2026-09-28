<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Zef\Framework\Exception\InvalidConfigurationException;

final readonly class MiddlewareDefinition
{
    public function __construct(
        public string $serviceId,
        public int $priority = 0,
        public ?string $group = null,
        public array $tags = [],
    ) {
        if ($serviceId === '') {
            throw new \InvalidArgumentException('Middleware service ID must not be empty.');
        }
        if ($group !== null && $group === '') {
            throw new \InvalidArgumentException('Middleware group must not be empty when provided.');
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new \InvalidArgumentException('Middleware tags must be non-empty strings.');
            }
        }
    }

    public static function fromArray(array $config): self
    {
        $service = $config['service'] ?? $config['id'] ?? null;
        if (!is_string($service) || $service === '') {
            throw new InvalidConfigurationException('Middleware definition requires a non-empty service ID.');
        }
        $priority = $config['priority'] ?? 0;
        if (!is_int($priority) && !is_float($priority) && !is_string($priority)) {
            throw new InvalidConfigurationException("Middleware '{$service}' priority must be numeric.");
        }
        // Regresi I-6 (issue #174): `group` and `tags` are parsed and
        // validated but RESERVED FOR FUTURE USE (conditional stacks /
        // tag-based wiring) — no behaviour keys off them yet, deliberately
        // not implemented speculatively. `priority` IS used: PipelineFactory
        // orders the pipeline by it (highest first, declaration order on
        // ties).
        $tags = $config['tags'] ?? [];
        if (!is_array($tags)) {
            throw new InvalidConfigurationException("Middleware '{$service}' tags must be an array.");
        }
        $group = $config['group'] ?? null;
        if ($group !== null && !is_string($group)) {
            throw new InvalidConfigurationException("Middleware '{$service}' group must be a string or null.");
        }

        try {
            return new self(
                serviceId: $service,
                priority: (int) $priority,
                group: $group,
                tags: array_values($tags),
            );
        } catch (\InvalidArgumentException $e) {
            throw new InvalidConfigurationException($e->getMessage(), 0, $e);
        }
    }

    public static function fromLegacy(array|string $config): self
    {
        if (is_string($config)) {
            return new self($config);
        }

        return self::fromArray($config);
    }
}
