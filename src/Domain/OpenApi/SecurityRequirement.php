<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Security Requirement Object — a map of security scheme name to
 * the list of OAuth scopes requested by an operation.
 */
final readonly class SecurityRequirement
{
    /**
     * @param array<string, list<string>> $requirements
     */
    public function __construct(
        public array $requirements = [],
    ) {
        if ($requirements === []) {
            throw new SchemaDefinitionException('SecurityRequirement must reference at least one scheme.');
        }
        foreach ($requirements as $scheme => $scopes) {
            if (!is_string($scheme) || trim($scheme) === '') {
                throw new SchemaDefinitionException('SecurityRequirement scheme names must be non-empty strings.');
            }
            if (!is_array($scopes)) {
                throw new SchemaDefinitionException(
                    "SecurityRequirement scopes for '{$scheme}' must be a list of strings."
                );
            }
            foreach ($scopes as $scope) {
                if (!is_string($scope)) {
                    throw new SchemaDefinitionException(
                        "SecurityRequirement scopes for '{$scheme}' must be a list of strings."
                    );
                }
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->requirements;
    }
}
