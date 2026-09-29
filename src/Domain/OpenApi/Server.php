<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Server Object — one deployment target of the API.
 *
 * Variables are simple string substitutions rendered by client tooling
 * ({"protocol": "https"} style); the url may reference them as {name}.
 */
final readonly class Server
{
    /**
     * @param array<string, string> $variables
     */
    public function __construct(
        public string $url,
        public string $description = '',
        public array $variables = [],
    ) {
        if (trim($url) === '') {
            throw new \InvalidArgumentException('Server url must not be empty.');
        }
        foreach ($variables as $key => $value) {
            if (!is_string($key) || trim($key) === '' || !is_string($value)) {
                throw new \InvalidArgumentException(
                    'Server variables must map non-empty string names to string values.',
                );
            }
        }
    }

    /**
     * @return array{url: string, description?: string, variables?: array<string, array{default: string}>}
     */
    public function toArray(): array
    {
        $out = ['url' => $this->url];
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }
        if ($this->variables !== []) {
            $out['variables'] = array_map(
                static fn (string $default): array => ['default' => $default],
                $this->variables,
            );
        }

        return $out;
    }
}
