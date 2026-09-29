<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * @internal
 *
 * Maps the first recognized OpenAPI security scheme onto Postman's
 * collection-level auth section (moved out of the PostmanCollectionExporter
 * so the exporter stays within the class-size budget; behaviour carried
 * over unchanged)
 */
final class PostmanAuthBuilder
{
    /**
     * First recognized security scheme as Postman collection-level auth.
     *
     * @param array<array-key, mixed> $securitySchemes
     *
     * @return null|array<string, mixed>
     */
    public static function build(array $securitySchemes): ?array
    {
        foreach ($securitySchemes as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $auth = self::authForScheme($scheme);
            if ($auth !== null) {
                return $auth;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $scheme
     *
     * @return null|array<string, mixed>
     */
    private static function authForScheme(array $scheme): ?array
    {
        $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : '';
        if ($type === 'http') {
            return self::httpAuth($scheme);
        }
        if ($type === 'apiKey') {
            return self::apiKeyAuth($scheme);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $scheme
     *
     * @return null|array<string, mixed>
     */
    private static function httpAuth(array $scheme): ?array
    {
        $httpScheme = is_string($scheme['scheme'] ?? null) ? $scheme['scheme'] : '';
        if ($httpScheme === 'bearer') {
            return [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '<bearer-token>', 'type' => 'string']],
            ];
        }
        if ($httpScheme === 'basic') {
            return [
                'type' => 'basic',
                'basic' => [
                    ['key' => 'username', 'value' => '<username>', 'type' => 'string'],
                    ['key' => 'password', 'value' => '<password>', 'type' => 'string'],
                ],
            ];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $scheme
     *
     * @return array<string, mixed>
     */
    private static function apiKeyAuth(array $scheme): array
    {
        $in = is_string($scheme['in'] ?? null) ? $scheme['in'] : 'header';

        return [
            'type' => 'apikey',
            'apikey' => [
                ['key' => 'in', 'value' => $in, 'type' => 'string'],
                ['key' => 'key', 'value' => '<api-key-name>', 'type' => 'string'],
                ['key' => 'value', 'value' => '<api-key-value>', 'type' => 'string'],
            ],
        ];
    }
}
