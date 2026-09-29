<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound HTTP adapter)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Http;

/**
 * Result of an API version negotiation (immutable).
 *
 * $source ∈ {'path','header','query','default'} — which channel supplied the
 * winning version. Never contains user-controlled free-form data beyond the
 * validated version token itself.
 */
final readonly class ApiVersion
{
    public const string SOURCE_PATH = 'path';
    public const string SOURCE_HEADER = 'header';
    public const string SOURCE_QUERY = 'query';
    public const string SOURCE_DEFAULT = 'default';

    private const array VALID_SOURCES = [
        self::SOURCE_PATH,
        self::SOURCE_HEADER,
        self::SOURCE_QUERY,
        self::SOURCE_DEFAULT,
    ];

    public function __construct(
        public string $version,
        public string $source,
    ) {
        if ($this->version === '') {
            throw new \InvalidArgumentException('API version must not be empty.');
        }
        if (!in_array($this->source, self::VALID_SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown API version source '{$this->source}'.");
        }
    }
}
