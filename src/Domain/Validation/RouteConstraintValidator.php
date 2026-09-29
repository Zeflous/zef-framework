<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\RouteConstraintException;

final class RouteConstraintValidator
{
    private const array BUILT_IN = [
        'int' => '/^\d+$/',
        'uint' => '/^[1-9]\d*$/',
        'alpha' => '/^[a-zA-Z]+$/',
        'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        'hex' => '/^[0-9a-f]+$/i',
    ];

    private array $custom = [];

    /**
     * @var array<string, callable(string): bool>
     */
    private array $compiled = [];

    public function addCustom(string $name, string $regex): void
    {
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidConfigurationException("Invalid route constraint name '{$name}'.");
        }
        if ($regex === '' || strlen($regex) > 2048) {
            throw new InvalidConfigurationException(
                "Invalid route constraint regex '{$name}': pattern length must be between 1 and 2048 bytes.",
            );
        }
        if (preg_match('/\((?:\?:|\?>|\?<[^>]+>)?[^)]*[+*][^)]*\)[+*](?:[?+])?/', $regex) === 1) {
            throw new InvalidConfigurationException(
                "Invalid route constraint regex '{$name}': nested quantified groups are not permitted "
                . 'by the ReDoS safety policy.',
            );
        }
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): bool {
                throw new RouteConstraintPatternException($message, 0, $severity, $file, $line);
            }
        );

        try {
            try {
                $result = preg_match($regex, '');
            } catch (\ValueError $e) {
                throw new InvalidConfigurationException(
                    "Invalid route constraint regex '{$name}': {$e->getMessage()}",
                    0,
                    $e,
                );
            }
        } catch (\ErrorException $e) {
            throw new InvalidConfigurationException(
                "Invalid route constraint regex '{$name}': {$e->getMessage()}",
                0,
                $e,
            );
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw new InvalidConfigurationException(
                "Invalid route constraint regex '{$name}': " . preg_last_error_msg(),
            );
        }
        $this->custom[$name] = $regex;
        unset($this->compiled[$name]);
    }

    public function test(string $param, string $type, string $value): bool
    {
        $this->assertKnown($type);
        $matcher = $this->compiled[$type] ??= $this->compile($type);

        try {
            return $matcher($value);
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidConfigurationException(
                "Route constraint '{$type}' failed during evaluation: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    public function assertKnown(string $type): void
    {
        if (!isset($this->custom[$type]) && !isset(self::BUILT_IN[$type])) {
            throw new InvalidConfigurationException("Unknown route constraint type '{$type}'.");
        }
    }

    public function assert(string $param, string $type, string $value): void
    {
        if (!$this->test($param, $type, $value)) {
            throw new RouteConstraintException($param, $type, $value);
        }
    }

    /**
     * v2.10.0: snapshot of custom constraints for route-cache export
     * (built-ins are implicit on any fresh validator, only customs travel).
     *
     * @return array<string,string> name => regex
     */
    public function customConstraints(): array
    {
        return $this->custom;
    }

    /** @return callable(string): bool */
    private function compile(string $type): callable
    {
        if (isset(self::BUILT_IN[$type])) {
            return match ($type) {
                'int' => static fn (string $v): bool => $v !== '' && preg_match('/^\d+$/D', $v) === 1,
                'uint' => static fn (string $v): bool => $v !== '' && $v[0] !== '0' && preg_match('/^\d+$/D', $v) === 1,
                'alpha' => static fn (string $v): bool => $v !== '' && preg_match('/^[a-zA-Z]+$/D', $v) === 1,
                'slug' => static fn (string $v): bool => $v !== ''
                    && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $v) === 1,
                'uuid' => static fn (string $v): bool => preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
                    $v,
                ) === 1,
                'hex' => static fn (string $v): bool => $v !== '' && preg_match('/^[0-9a-f]+$/Di', $v) === 1,
                default => throw new InvalidConfigurationException("Unknown route constraint type '{$type}'."),
            };
        }
        $regex = $this->custom[$type];

        return static function (string $value) use ($regex, $type): bool {
            $matched = preg_match($regex, $value);
            if ($matched === false) {
                throw new InvalidConfigurationException(
                    "Route constraint '{$type}' failed during evaluation: " . preg_last_error_msg(),
                );
            }

            return $matched === 1;
        };
    }
}
