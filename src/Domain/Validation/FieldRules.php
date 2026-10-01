<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * Fluent rule chain for one field. Rules run in registration order; the
 * first failing rule wins (later rules see the original value).
 *
 * ReDoS policy: pattern() enforces a bounded pattern length and a bounded
 * subject length before preg_match, mirroring the framework's ReDoS stance
 * for route constraints.
 */
final class FieldRules
{
    private const int MAX_PATTERN_LENGTH = 2048;
    private const int MAX_PATTERN_SUBJECT = 4096;
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /**
     * @var list<array{
     *     rule: string,
     *     params: array<string, mixed>,
     *     check: callable(mixed): bool,
     *     message: string,
     *     skipNull: bool,
     *     skipEmpty: bool,
     * }>
     */
    private array $rules = [];
    private bool $nullableChain = false;

    public function __construct(
        public readonly string $field,
    ) {}

    public function required(string $message = 'is required.'): self
    {
        return $this->add(
            'required',
            static fn (mixed $v): bool => !in_array($v, [null, '', []], true),
            $message,
            false,
            false,
        );
    }

    /** Value must be absent-or-null-safe string after casting checks below. */
    public function typeString(string $message = 'must be a string.'): self
    {
        return $this->add('type', static fn (mixed $v): bool => is_string($v), $message, params: ['kind' => 'string']);
    }

    public function typeInt(string $message = 'must be an integer.'): self
    {
        return $this->add(
            'type',
            static fn (mixed $v): bool => is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}$/', $v) === 1),
            $message,
            params: ['kind' => 'integer'],
        );
    }

    public function typeNumeric(string $message = 'must be numeric.'): self
    {
        return $this->add(
            'type',
            static fn (mixed $v): bool => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)),
            $message,
            params: ['kind' => 'numeric'],
        );
    }

    public function minLength(int $min, string $message = 'is too short.'): self
    {
        if ($min < 0) {
            throw new \InvalidArgumentException('minLength must be >= 0.');
        }

        return $this->add(
            'min_length',
            static fn (mixed $v): bool => is_string($v) && mb_strlen($v) >= $min,
            $message,
            params: ['min' => $min],
        );
    }

    public function maxLength(int $max, string $message = 'is too long.'): self
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('maxLength must be >= 1.');
        }

        return $this->add(
            'max_length',
            static fn (mixed $v): bool => is_string($v) && mb_strlen($v) <= $max,
            $message,
            params: ['max' => $max],
        );
    }

    public function min(float|int $bound, string $message = 'is too small.'): self
    {
        return $this->add(
            'min',
            // @infection-ignore-all CastFloat — ekuivalen: perbandingan >= dengan int/float menghasilkan hasil yang sama
            static fn (mixed $v): bool => is_numeric($v) && (float) $v >= $bound,
            $message,
            params: ['bound' => $bound],
        );
    }

    public function max(float|int $bound, string $message = 'is too large.'): self
    {
        return $this->add(
            'max',
            // @infection-ignore-all CastFloat — ekuivalen: perbandingan <= dengan int/float menghasilkan hasil yang sama
            static fn (mixed $v): bool => is_numeric($v) && (float) $v <= $bound,
            $message,
            params: ['bound' => $bound],
        );
    }

    public function email(string $message = 'is not a valid email address.'): self
    {
        return $this->add(
            'email',
            static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
            $message,
            params: ['format' => 'email'],
        );
    }

    public function uuid(string $message = 'is not a valid UUID.'): self
    {
        return $this->add(
            'uuid',
            static fn (mixed $v): bool => is_string($v) && preg_match(self::UUID_PATTERN, $v) === 1,
            $message,
            params: ['format' => 'uuid'],
        );
    }

    /** @param list<int|string> $allowed */
    public function in(array $allowed, string $message = 'is not one of the allowed values.'): self
    {
        if (array_any($allowed, static fn (mixed $value): bool => !is_string($value) && !is_int($value))) {
            throw new \InvalidArgumentException('in() accepts only string/int values.');
        }

        return $this->add(
            'in',
            static fn (mixed $v): bool => in_array($v, $allowed, true),
            $message,
            params: ['allowed' => $allowed],
        );
    }

    public function pattern(string $regex, string $message = 'does not match the required format.'): self
    {
        if ($regex === '' || strlen($regex) > self::MAX_PATTERN_LENGTH) {
            throw new \InvalidArgumentException('pattern() regex length must be 1..2048.');
        }

        return $this->add(
            'pattern',
            static function (mixed $v) use ($regex): bool {
                if (!is_string($v) || strlen($v) > self::MAX_PATTERN_SUBJECT) {
                    return false;
                }

                return preg_match($regex, $v) === 1;
            },
            $message,
            params: ['regex' => $regex],
        );
    }

    /** Custom predicate; receives the raw value. */
    public function custom(callable $check, string $message = 'is invalid.'): self
    {
        return $this->add('custom', $check, $message);
    }

    /** All subsequently registered rules are skipped for null values. */
    public function nullable(): self
    {
        $this->nullableChain = true;
        foreach ($this->rules as $index => $rule) {
            $this->rules[$index]['skipNull'] = true;
        }

        return $this;
    }

    /**
     * Structured descriptors of every registered rule, for documentation
     * tooling (OpenAPI schema inference) without exposing the closures.
     *
     * @return list<array{rule:string,params:array<string,mixed>,nullable:bool}>
     */
    public function definitions(): array
    {
        return array_map(
            fn (array $r): array => [
                'rule' => $r['rule'], 'params' => $r['params'], 'nullable' => $this->nullableChain,
            ],
            $this->rules,
        );
    }

    /**
     * Whether an empty ('' or []) value would skip every registered rule:
     * true when the chain is non-empty and all of its rules declare
     * skipEmpty. `required()` (skipEmpty=false) makes this false.
     */
    public function skipsEmpty(): bool
    {
        return $this->rules !== []
            && array_all($this->rules, static fn (array $rule): bool => $rule['skipEmpty']);
    }

    /** @return list<ValidationError> failures for the given raw value */
    public function validate(mixed $value): array
    {
        $errors = [];
        foreach ($this->rules as $rule) {
            if ($rule['skipNull'] && $value === null) {
                continue;
            }
            if ($rule['skipEmpty'] && ($value === '' || $value === [])) {
                continue;
            }
            $passed = ($rule['check'])($value);
            if ($passed !== true) {
                $errors[] = new ValidationError(
                    $this->field,
                    "Field '{$this->field}' {$rule['message']}",
                    $rule['rule'],
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function add(
        string $rule,
        callable $check,
        string $message,
        bool $skipNull = true,
        bool $skipEmpty = true,
        array $params = [],
    ): self {
        $this->rules[] = [
            'rule' => $rule,
            'params' => $params,
            'check' => $check,
            'message' => $message,
            'skipNull' => $skipNull,
            'skipEmpty' => $skipEmpty,
        ];

        return $this;
    }
}
