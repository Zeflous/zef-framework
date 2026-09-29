<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Issue #36 exit ramp: relocated Adapters -> Domain with the same FQN
 * and namespace (classmap + PSR-4 multi-directory both resolve it), so
 * every consumer — the Domain config aggregator (ModuleDefinition) and
 * all route-registration call sites — is untouched. The class is a pure
 * route spec value object: its only dependency is the Domain
 * HttpMethodValidator, and it has no outbound coupling, so it satisfies
 * the hexagonal rule the RouteDefSpec carve-out used to bypass.
 */

namespace Zef\Framework\Router;

use Zef\Framework\Validation\HttpMethodValidator;

final readonly class RouteDefinition
{
    public string $method;
    public string $path;
    public string $handler;

    /**
     * Optional route name for reverse routing (URL generation).
     */
    public ?string $name;

    public function __construct(
        string $method,
        string $path,
        string $handler,
        public int $priority = 0,
        ?string $name = null,
    ) {
        $method = strtoupper(trim($method));
        HttpMethodValidator::assert($method);
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException("Route path '{$path}' must begin with '/'.");
        }
        if ($handler === '') {
            throw new \InvalidArgumentException('Route handler service ID must not be empty.');
        }
        if ($name !== null) {
            $name = trim($name);
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
                throw new \InvalidArgumentException("Invalid route name '{$name}': expected [A-Za-z0-9._-]{1,128}.");
            }
        }
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
        $this->name = $name;
    }

    public static function fromArray(array $config): self
    {
        $method = $config['method'] ?? 'GET';
        $path = $config['path'] ?? '/';
        $handler = $config['handler'] ?? '';
        $priority = $config['priority'] ?? 0;
        if (!is_string($method) || !is_string($path) || !is_string($handler)) {
            throw new \InvalidArgumentException('Route definition method, path, and handler must be strings.');
        }
        $isNonNumericPriority = !is_int($priority) && !is_float($priority) && !is_string($priority);
        $isUnparsableStringPriority = is_string($priority) && !is_numeric($priority);
        if ($isNonNumericPriority || $isUnparsableStringPriority) {
            throw new \InvalidArgumentException('Route definition priority must be numeric.');
        }
        $name = $config['name'] ?? null;
        if ($name !== null && !is_string($name)) {
            throw new \InvalidArgumentException('Route definition name must be a string or null.');
        }

        return new self(
            method: strtoupper(trim($method)),
            path: $path,
            handler: $handler,
            priority: (int) $priority,
            name: $name,
        );
    }
}
