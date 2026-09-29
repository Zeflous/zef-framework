<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Immutable, bounded causal context for remote/distributed execution.
 */
final readonly class CorrelationContext
{
    public const int MAX_PROPAGATION_BYTES = 8192;
    public const int MAX_TRACEPARENT_BYTES = 55;
    public const int MAX_TRACESTATE_BYTES = 512;
    public const int MAX_OPERATION_ID_BYTES = 128;
    public const int MAX_IDEMPOTENCY_KEY_BYTES = 128;
    public const int MAX_ATTRIBUTES = 16;
    public const int MAX_ATTRIBUTE_KEY_BYTES = 64;
    public const int MAX_ATTRIBUTE_VALUE_BYTES = 256;
    public const int MAX_ATTRIBUTE_BYTES = 4096;

    /** W3C tracestate member: lowercase ASCII key, '='-separated ASCII value. */
    private const string TRACESTATE_KEY = '[a-z0-9][_a-z0-9\-*\/]{0,255}=';

    private const string TRACESTATE_VALUE = '[!#$%&\x27*+\-.\/0-9:<=>?@A-Z\^_`a-z|~]*';

    private const string TRACESTATE_MEMBER = self::TRACESTATE_KEY . self::TRACESTATE_VALUE;

    private const string TRACESTATE_PATTERN = '/^' . self::TRACESTATE_MEMBER
        . '(?:, ?' . self::TRACESTATE_MEMBER . ')*$/';

    /**
     * @var array<string, null|bool|float|int|string>
     */
    public array $attributes;

    /**
     * @param array<string, null|bool|float|int|string> $attributes
     */
    public function __construct(
        public string $traceId,
        public string $spanId,
        public string $traceFlags,
        public ?string $traceState,
        public string $operationId,
        public ?string $idempotencyKey = null,
        array $attributes = [],
    ) {
        $this->validateTraceId($traceId);
        $this->validateSpanId($spanId);
        $this->validateTraceFlags($traceFlags);
        $this->validateTraceState($traceState);
        $this->validateToken($operationId, 'operationId');
        $this->validateOptionalToken($idempotencyKey, 'idempotencyKey');
        $validatedAttributes = $this->validateAttributes($attributes);
        $this->attributes = $validatedAttributes;
        $wireSize = $this->traceParentLength($traceId, $spanId, $traceFlags)
            + ($traceState === null ? 0 : strlen($traceState) + 1);
        if ($wireSize > self::MAX_PROPAGATION_BYTES) {
            throw new \InvalidArgumentException('Correlation propagation exceeds the hard size limit.');
        }
    }

    public function traceParent(): string
    {
        return '00-' . $this->traceId . '-' . $this->spanId . '-' . $this->traceFlags;
    }

    public function propagationBytes(): int
    {
        return $this->traceParentLength($this->traceId, $this->spanId, $this->traceFlags)
            + ($this->traceState === null ? 0 : strlen($this->traceState) + 1);
    }

    /** @return array<string, null|bool|float|int|string> */
    public function redactedAttributes(): array
    {
        $out = [];
        foreach ($this->attributes as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $out[$key] = is_string($value) ? 'sha256:' . hash('sha256', $value) : '[REDACTED]';

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function validateTraceId(string $value): void
    {
        if (preg_match('/^[0-9a-f]{32}$/', $value) !== 1 || $value === str_repeat('0', 32)) {
            throw new \InvalidArgumentException('Invalid W3C trace ID.');
        }
    }

    private function validateSpanId(string $value): void
    {
        if (preg_match('/^[0-9a-f]{16}$/', $value) !== 1 || $value === str_repeat('0', 16)) {
            throw new \InvalidArgumentException('Invalid W3C span ID.');
        }
    }

    private function validateTraceFlags(string $value): void
    {
        if (preg_match('/^[0-9a-f]{2}$/', $value) !== 1 || ((hexdec($value) & 0xFE) !== 0)) {
            throw new \InvalidArgumentException('Invalid W3C trace flags.');
        }
    }

    private function validateTraceState(?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (
            $value === ''
            || strlen($value) > self::MAX_TRACESTATE_BYTES
            || preg_match(self::TRACESTATE_PATTERN, $value) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid or oversized W3C tracestate.');
        }
    }

    private function validateToken(string $value, string $name): void
    {
        $max = $name === 'operationId' ? self::MAX_OPERATION_ID_BYTES : self::MAX_IDEMPOTENCY_KEY_BYTES;
        if ($value === '' || strlen($value) > $max || preg_match('/^[\x21-\x7E]+$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be a bounded printable token.', $name));
        }
    }

    private function validateOptionalToken(?string $value, string $name): void
    {
        if ($value !== null) {
            $this->validateToken($value, $name);
        }
    }

    /**
     * @param array<mixed> $attributes
     *
     * @return array<string, null|bool|float|int|string>
     */
    private function validateAttributes(array $attributes): array
    {
        if (count($attributes) > self::MAX_ATTRIBUTES) {
            throw new \InvalidArgumentException('Correlation attributes exceed the maximum count.');
        }
        $normalized = [];
        $bytes = 0;
        foreach ($attributes as $key => $value) {
            if (
                !is_string($key)
                || $key === ''
                || strlen($key) > self::MAX_ATTRIBUTE_KEY_BYTES
                || preg_match('/^[A-Za-z0-9_.-]+$/', $key) !== 1
            ) {
                throw new \InvalidArgumentException('Invalid correlation attribute key.');
            }
            $isScalarValue = is_string($value) || is_int($value) || is_float($value) || is_bool($value);
            if (!$isScalarValue && $value !== null) {
                throw new \InvalidArgumentException('Correlation attribute values must be scalar or null.');
            }
            if (is_string($value) && strlen($value) > self::MAX_ATTRIBUTE_VALUE_BYTES) {
                throw new \InvalidArgumentException('Correlation attribute string value exceeds the maximum length.');
            }
            $bytes += strlen($key) + $this->scalarByteLength($value);
            if ($bytes > self::MAX_ATTRIBUTE_BYTES) {
                throw new \InvalidArgumentException('Correlation attributes exceed the aggregate size limit.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function scalarByteLength(bool|float|int|string|null $value): int
    {
        return match (true) {
            $value === null => 4,
            is_bool($value) => $value ? 4 : 5,
            is_int($value) => 20,
            is_float($value) => 24,
            default => strlen($value),
        };
    }

    private function traceParentLength(string $traceId, string $spanId, string $flags): int
    {
        return 2 + 1 + strlen($traceId) + 1 + strlen($spanId) + 1 + strlen($flags);
    }

    private function isSensitiveKey(string $key): bool
    {
        $value = strtolower($key);

        return array_any(
            ['authorization', 'cookie', 'token', 'secret', 'password', 'credential', 'body', 'idempotency'],
            fn (string $needle): bool => str_contains($value, $needle),
        );
    }
}
