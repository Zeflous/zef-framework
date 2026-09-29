<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * Centralised regex constants and assertion helpers.
 * Replaces ~12 inline copies of the same patterns.
 */
final class Identifier
{
    public const string OPAQUE_ID_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';
    public const string MODULE_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.-]*$/';
    /** W3C traceparent: 2-hex version, 32-hex trace id, 16-hex span id, 2-hex flags, optional tracestate. */
    public const string TRACEPARENT_PATTERN =
        '/^[0-9a-f]{2}-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}(?:-[^\s]{1,512})?$/i';
    public const string MESSAGE_TYPE_PATTERN = '/^[A-Za-z0-9._:\/-]{1,255}$/';

    public static function assertOpaqueId(string $value, string $field = 'ID'): void
    {
        if (preg_match(self::OPAQUE_ID_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field}.");
        }
    }

    public static function assertTraceParent(string $value, string $field = 'traceparent'): void
    {
        if (preg_match(self::TRACEPARENT_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field}.");
        }
    }

    public static function assertModuleName(string $value, string $field = 'module name'): void
    {
        if ($value === '' || preg_match(self::MODULE_NAME_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field} '{$value}'.");
        }
    }

    public static function assertMessageType(string $value, string $field = 'message type'): void
    {
        if (preg_match(self::MESSAGE_TYPE_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$field}.");
        }
    }

    public static function isValidOpaqueId(string $value): bool
    {
        return preg_match(self::OPAQUE_ID_PATTERN, $value) === 1;
    }
}
