<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Domain layer: value objects)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

/**
 * A single rate-limit tier: how much of WHAT is allowed for WHOM.
 *
 * The rule is transport-agnostic (plain strings, no PSR types) so it stays a
 * pure Domain value object; the HTTP adapter decides which rules match a
 * given request via {@see RateLimitRule::matchesPath()} and
 * {@see RateLimitRule::matchesMethod()}.
 *
 * Field semantics:
 * - `name` — stable tier identifier ("api", "auth", "expensive"). It becomes
 *   part of the storage key, so renaming a rule silently resets every
 *   counter; names must not contain ">" (reserved as the key separator by
 *   {@see TieredRateLimiter}).
 * - `limit` / `windowSeconds` — the classic quota pair (N events per window).
 * - `cost` — how many units ONE hit consumes (weighted requests, e.g. a
 *   report generation costs 5, a read costs 1). Must satisfy 1 <= cost <=
 *   limit, otherwise the rule can never admit a single request.
 * - `pathPrefix` — request paths starting with this prefix (or equal to it)
 *   match; "/" matches everything.
 * - `methods` — HTTP methods the rule applies to; null = all methods.
 *
 * Instances are immutable; validation happens eagerly in the constructor so
 * a malformed tier fails at boot, not at request time.
 */
final readonly class RateLimitRule
{
    /**
     * Trailing-slash normalised at construction ("/api/" becomes "/api";
     * the bare root "/" is its own normal form).
     */
    public string $pathPrefix;

    /**
     * @param null|list<string> $methods
     */
    public function __construct(
        public string $name,
        public int $limit,
        public int $windowSeconds,
        public int $cost = 1,
        string $pathPrefix = '/',
        public ?array $methods = null,
    ) {
        $this->assertName($name);
        $this->assertQuota($limit, $windowSeconds, $cost);
        $this->pathPrefix = self::normalisedPathPrefix($pathPrefix);
        $this->assertMethods($methods);
    }

    /**
     * Builds a rule from a config/environment map with strict key checking
     * (fail-fast on typos instead of silently dropping a tier).
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException on missing, unknown or invalid keys
     */
    public static function fromArray(array $data): self
    {
        self::assertRequiredKeysPresent($data);
        self::assertNoUnknownKeys($data);
        $name = self::asString($data['name'], 'Rate limit rule "name" must be a string.');
        $limit = self::asInt($data['limit'], 'Rate limit rule "limit" must be an integer.');
        $window = self::asInt($data['windowSeconds'], 'Rate limit rule "windowSeconds" must be an integer.');
        $cost = self::asInt($data['cost'] ?? 1, 'Rate limit rule "cost" must be an integer.');
        $pathPrefix = self::asString($data['pathPrefix'] ?? '/', 'Rate limit rule "pathPrefix" must be a string.');
        $methods = self::normalisedMethods($data['methods'] ?? null);

        return new self($name, $limit, $window, $cost, $pathPrefix, $methods);
    }

    /**
     * True when the request path falls into this rule's bucket: the path is
     * equal to the prefix, or extends it at a segment boundary ("/api"
     * matches "/api" and "/api/users", but NOT "/apiv2"). The prefix is
     * already trailing-slash-normalised at construction, so no trimming
     * happens here; the bare root "/" matches everything.
     */
    public function matchesPath(string $path): bool
    {
        if ($this->pathPrefix === '/') {
            return true;
        }

        return $path === $this->pathPrefix || str_starts_with($path, $this->pathPrefix . '/');
    }

    /**
     * True when the rule applies to the HTTP method (null = all methods).
     */
    public function matchesMethod(string $method): bool
    {
        return $this->methods === null || in_array(strtoupper($method), $this->methods, true);
    }

    private function assertName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Rate limit rule name must not be empty.');
        }
        if (str_contains($name, '>')) {
            throw new \InvalidArgumentException('Rate limit rule name must not contain ">".');
        }
        if (strlen($name) > 64) {
            throw new \InvalidArgumentException('Rate limit rule name must be at most 64 characters.');
        }
    }

    private function assertQuota(int $limit, int $windowSeconds, int $cost): void
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Rate limit rule limit must be >= 1.');
        }
        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit rule windowSeconds must be >= 1.');
        }
        if ($cost < 1) {
            throw new \InvalidArgumentException('Rate limit rule cost must be >= 1.');
        }
        if ($cost > $limit) {
            throw new \InvalidArgumentException('Rate limit rule cost must not exceed the limit.');
        }
    }

    /**
     * Normalises away trailing slashes so "/api/" and "/api" behave
     * identically — a config typo must not change the matched set. The
     * bare root "/" is its own normal form.
     */
    private static function normalisedPathPrefix(string $pathPrefix): string
    {
        if ($pathPrefix === '' || !str_starts_with($pathPrefix, '/')) {
            throw new \InvalidArgumentException('Rate limit rule pathPrefix must start with "/".');
        }
        $pathPrefix = rtrim($pathPrefix, '/');
        if ($pathPrefix === '') {
            $pathPrefix = '/';
        }

        return $pathPrefix;
    }

    /** @param null|list<string> $methods */
    private function assertMethods(?array $methods): void
    {
        if ($methods === null) {
            return;
        }
        if ($methods === []) {
            throw new \InvalidArgumentException('Rate limit rule methods must be null (all) or a non-empty list.');
        }
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match('/^[A-Z]+$/', $method) !== 1) {
                // php:S5867: HTTP method tokens are ASCII-uppercase by design.
                throw new \InvalidArgumentException(
                    'Rate limit rule methods must be uppercase HTTP method tokens.',
                );
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function assertRequiredKeysPresent(array $data): void
    {
        foreach (['name', 'limit', 'windowSeconds'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new \InvalidArgumentException(sprintf('Rate limit rule is missing the "%s" key.', $required));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function assertNoUnknownKeys(array $data): void
    {
        $known = ['name', 'limit', 'windowSeconds', 'cost', 'pathPrefix', 'methods'];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $known, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown rate limit rule key "%s".', (string) $key));
            }
        }
    }

    private static function asString(mixed $value, string $message): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    private static function asInt(mixed $value, string $message): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * @param mixed $methods
     *
     * @return null|list<string>
     */
    private static function normalisedMethods($methods): ?array
    {
        if ($methods === null) {
            return null;
        }
        if (!is_array($methods) || array_values($methods) !== $methods) {
            throw new \InvalidArgumentException('Rate limit rule "methods" must be a list of strings.');
        }
        $list = [];
        foreach ($methods as $method) {
            if (!is_string($method)) {
                throw new \InvalidArgumentException('Rate limit rule "methods" must be a list of strings.');
            }
            $list[] = $method;
        }

        return $list;
    }
}
