<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Security-related slice of {@see OpenApiSpecValidator}: the per-operation
 * security requirements and the components.securitySchemes inventory.
 * Extracted to keep OpenApiSpecValidator within the class-size budget
 * (php:S2042); validation behavior and error strings are byte-identical to
 * the former private methods of the validator.
 */
final class OpenApiSecurityValidator
{
    /**
     * @param array<mixed, mixed> $operation
     * @param list<string>        $errors
     */
    public static function validateOperationSecurity(string $where, array $operation, array &$errors): void
    {
        $security = is_array($operation['security'] ?? null) ? $operation['security'] : [];
        foreach ($security as $requirement) {
            if (!is_array($requirement)) {
                $errors[] = "Operation {$where} contains a malformed security requirement.";
            }
        }
    }

    /**
     * @param array<mixed, mixed> $components
     * @param list<string>        $errors
     */
    public static function validateSchemes(array $components, array &$errors): void
    {
        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];
        foreach ($securitySchemes as $name => $scheme) {
            self::validateSecurityScheme($name, $scheme, $errors);
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function validateSecurityScheme(mixed $name, mixed $scheme, array &$errors): void
    {
        if (!is_string($name) || $name === '') {
            $errors[] = 'Security scheme names must be non-empty strings.';

            return;
        }
        if (!is_array($scheme) || !is_string($scheme['type'] ?? null)) {
            $errors[] = "Security scheme '{$name}' must be an object with a type.";

            return;
        }
        $type = $scheme['type'];
        if ($type === 'http' && (!is_string($scheme['scheme'] ?? null) || $scheme['scheme'] === '')) {
            $errors[] = "Security scheme '{$name}' of type http must define a scheme.";
        }
        if ($type === 'apiKey' && !in_array($scheme['in'] ?? null, ['query', 'header', 'cookie'], true)) {
            $errors[] = "Security scheme '{$name}' of type apiKey must define in: query|header|cookie.";
        }
        if ($type === 'openIdConnect' && !is_string($scheme['openIdConnectUrl'] ?? null)) {
            $errors[] = "Security scheme '{$name}' of type openIdConnect must define openIdConnectUrl.";
        }
    }
}
