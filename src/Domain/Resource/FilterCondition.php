<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * One resolved filter condition (immutable).
 *
 * The $field value is guaranteed to come from the caller-supplied whitelist —
 * it is safe to map to a storage column name. $value is always a scalar
 * string (or a list of strings for the `in` operator).
 */
final readonly class FilterCondition
{
    public const string EQ = 'eq';
    public const string NEQ = 'neq';
    public const string GT = 'gt';
    public const string GTE = 'gte';
    public const string LT = 'lt';
    public const string LTE = 'lte';
    public const string LIKE = 'like';
    public const string IN = 'in';

    public const array OPS = [self::EQ, self::NEQ, self::GT, self::GTE, self::LT, self::LTE, self::LIKE, self::IN];

    /**
     * @param list<string>|string $value
     */
    public function __construct(
        public string $field,
        public string $op,
        public array|string $value,
    ) {
        if ($this->field === '') {
            throw new \InvalidArgumentException('Filter condition field must not be empty.');
        }
        if (!in_array($this->op, self::OPS, true)) {
            throw new \InvalidArgumentException("Unknown filter operator '{$this->op}'.");
        }
        if ($this->op === self::IN) {
            if (!is_array($this->value) || $this->value === []) {
                throw new \InvalidArgumentException("Filter operator 'in' requires a non-empty list of values.");
            }
        } elseif (is_array($this->value)) {
            throw new \InvalidArgumentException("Filter operator '{$this->op}' requires a scalar value.");
        } else {
            /* scalar value with a scalar operator: the valid combination */
        }
    }
}
