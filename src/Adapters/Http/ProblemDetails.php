<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound adapters)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\ResponseInterface;
use Zef\Framework\Constant\HttpReasonPhrases;
use Zef\Framework\Validation\HttpStatusValidator;

/**
 * RFC 9457 "Problem Details for HTTP APIs" response factory.
 *
 * Produces `application/problem+json` documents with the standard members
 * (type, title, status, detail, instance) plus arbitrary extension members.
 * Titles default to the framework's RFC 9110 reason phrases; unknown
 * extensions must be scalar/string arrays (validated before encoding).
 */
final readonly class ProblemDetails
{
    /**
     * @param array<string,mixed> $extensions RFC 9457 extension members
     */
    public function __construct(
        public int $status,
        public string $title,
        public string $type = 'about:blank',
        public string $detail = '',
        public ?string $instance = null,
        public array $extensions = [],
    ) {
        $this->assertValidStatus();
        if ($this->title === '') {
            throw new \InvalidArgumentException('Problem details title must not be empty.');
        }
        if ($this->type === '') {
            throw new \InvalidArgumentException('Problem details type must not be empty.');
        }
        foreach ($this->extensions as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name) !== 1) {
                throw new \InvalidArgumentException("Invalid problem details extension name '{$name}'.");
            }
        }
    }

    public static function fromStatus(
        int $status,
        string $detail = '',
        ?string $instance = null,
        array $extensions = [],
    ): self {
        return new self(
            status: $status,
            title: HttpReasonPhrases::MAP[$status] ?? 'Unknown',
            type: 'about:blank',
            detail: $detail,
            instance: $instance,
            extensions: $extensions,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];
        if ($this->detail !== '') {
            $payload['detail'] = $this->detail;
        }
        if ($this->instance !== null && $this->instance !== '') {
            $payload['instance'] = $this->instance;
        }
        foreach ($this->extensions as $name => $value) {
            $payload[$name] = $value;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function toResponse(): ResponseInterface
    {
        return new Response(
            $this->status,
            ['Content-Type' => 'application/problem+json'],
            $this->toJson(),
        );
    }

    /**
     * php:S2830: the stateless Domain validator is built through a private
     * helper instead of a bare `new` in the constructor — the promoted
     * property constructor is public API and cannot grow an injection point.
     */
    private function assertValidStatus(): void
    {
        new HttpStatusValidator()->assert($this->status);
    }
}
