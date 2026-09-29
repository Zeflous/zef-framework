<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Security Scheme Object — describes HOW an operation is secured.
 *
 * Type-specific invariants mirror the specification:
 *   - http:            `scheme` is required (bearer, basic, ...).
 *   - apiKey:          `in` (parameter location) is required.
 *   - openIdConnect:   `openIdConnectUrl` is required (must be a URL).
 *   - oauth2:          flows may be attached (kept as raw structures).
 */
final readonly class SecurityScheme
{
    /**
     * @param array<string, mixed> $flows
     */
    public function __construct(
        public SecuritySchemeType $type,
        public ?string $scheme = null,
        public ?string $bearerFormat = null,
        public ?ParameterLocation $in = null,
        public ?string $openIdConnectUrl = null,
        public string $description = '',
        public array $flows = [],
    ) {
        $this->assertRequiredTypeFields();
        $this->assertTypeExclusiveFields();
    }

    /**
     * @return array{
     *     type: string,
     *     description?: string,
     *     scheme?: string,
     *     bearerFormat?: string,
     *     in?: string,
     *     openIdConnectUrl?: string,
     *     flows?: array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type->value];
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->scheme !== null) {
            $out['scheme'] = $this->scheme;
        }
        if ($this->bearerFormat !== null) {
            $out['bearerFormat'] = $this->bearerFormat;
        }
        if ($this->in instanceof ParameterLocation) {
            $out['in'] = $this->in->value;
        }
        if ($this->openIdConnectUrl !== null) {
            $out['openIdConnectUrl'] = $this->openIdConnectUrl;
        }
        if ($this->flows !== []) {
            $out['flows'] = $this->flows;
        }

        return $out;
    }

    private function assertRequiredTypeFields(): void
    {
        if ($this->type === SecuritySchemeType::Http && ($this->scheme === null || trim($this->scheme) === '')) {
            throw new SchemaDefinitionException('Http security scheme requires a non-empty scheme (e.g. "bearer").');
        }
        if ($this->type === SecuritySchemeType::ApiKey && !$this->in instanceof ParameterLocation) {
            throw new SchemaDefinitionException('ApiKey security scheme requires a parameter location (in).');
        }
        $connectUrlMissing = $this->openIdConnectUrl === null
            || filter_var($this->openIdConnectUrl, FILTER_VALIDATE_URL) === false;
        if ($this->type === SecuritySchemeType::OpenIdConnect && $connectUrlMissing) {
            throw new SchemaDefinitionException('OpenIdConnect security scheme requires a valid openIdConnectUrl.');
        }
    }

    private function assertTypeExclusiveFields(): void
    {
        if ($this->scheme !== null && $this->type !== SecuritySchemeType::Http) {
            throw new SchemaDefinitionException('Security scheme `scheme` is only valid for the http type.');
        }
        if ($this->in instanceof ParameterLocation && $this->type !== SecuritySchemeType::ApiKey) {
            throw new SchemaDefinitionException('Security scheme `in` is only valid for the apiKey type.');
        }
        if ($this->openIdConnectUrl !== null && $this->type !== SecuritySchemeType::OpenIdConnect) {
            throw new SchemaDefinitionException(
                'Security scheme `openIdConnectUrl` is only valid for the openIdConnect type.',
            );
        }
        if ($this->bearerFormat !== null && ($this->scheme !== 'bearer')) {
            throw new SchemaDefinitionException('Security scheme `bearerFormat` requires scheme "bearer".');
        }
    }
}
