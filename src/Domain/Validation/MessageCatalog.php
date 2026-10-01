<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * Immutable catalog of localized validation templates.
 *
 * Lookup order for templateFor($locale, $rule):
 *   exact locale ("id_ID") → base locale ("id") → wildcard ("*") → null.
 *
 * Templates use {{field}}, {{label}} and {{rule}} placeholders, interpolated
 * by ValidationTranslator. Template contents come from the application
 * developer (never from user input), so no escaping is applied here.
 */
final readonly class MessageCatalog
{
    public const int MAX_TEMPLATE_BYTES = 512;

    /**
     * @var array<string,array<string,string>> locale => rule => template
     */
    private array $templates;

    /** @param array<string,array<string,string>> $templates */
    private function __construct(array $templates = [])
    {
        foreach ($templates as $locale => $rules) {
            if (!is_string($locale) || ($locale !== '*' && !self::isValidLocale($locale))) {
                throw new \InvalidArgumentException("Invalid catalog locale '" . $locale . "'.");
            }
            foreach ($rules as $rule => $template) {
                // @infection-ignore-all Throw_ — ekuivalen: guard duplikat; constructor sudah memvalidasi kunci rule yang sama
                if (!is_string($rule) || $rule === '' || strlen($rule) > 64) {
                    throw new \InvalidArgumentException('Catalog rule keys must be 1..64 byte strings.');
                }
                // @infection-ignore-all Throw_ — ekuivalen: guard duplikat; constructor sudah memvalidasi template yang sama
                if (!is_string($template) || $template === '' || strlen($template) > self::MAX_TEMPLATE_BYTES) {
                    throw new \InvalidArgumentException(
                        "Catalog template for '{$locale}/{$rule}' must be 1.." . self::MAX_TEMPLATE_BYTES . ' bytes.'
                    );
                }
            }
        }
        $this->templates = $templates;
    }

    public static function empty(): self
    {
        return new self();
    }

    /** English templates matching the v2.8.0 rules engine rule names. */
    public static function defaultEnglish(): self
    {
        return new self([
            '*' => [
                'required' => '{{label}} is required.',
                'type' => '{{label}} must be a string.',
                'min_length' => '{{label}} is too short.',
                'max_length' => '{{label}} is too long.',
                'min' => '{{label}} is too small.',
                'max' => '{{label}} is too large.',
                'email' => '{{label}} is not a valid email address.',
                'uuid' => '{{label}} is not a valid UUID.',
                'in' => '{{label}} is not one of the allowed values.',
                'pattern' => '{{label}} does not match the required format.',
                'custom' => '{{label}} is invalid.',
            ],
        ]);
    }

    /** @param array<string,string> $rules rule => template */
    public function with(string $locale, array $rules): self
    {
        $locale = strtolower(trim($locale));
        if (!self::isValidLocale($locale)) {
            throw new \InvalidArgumentException("Invalid locale '{$locale}'. Expected e.g. 'id', 'en', 'id_ID'.");
        }
        $merged = $this->templates;
        foreach ($rules as $rule => $template) {
            if (!is_string($rule) || $rule === '' || strlen($rule) > 64) {
                throw new \InvalidArgumentException('Catalog rule keys must be 1..64 byte strings.');
            }
            if (!is_string($template) || $template === '' || strlen($template) > self::MAX_TEMPLATE_BYTES) {
                throw new \InvalidArgumentException(
                    "Catalog template for '{$locale}/{$rule}' must be 1.." . self::MAX_TEMPLATE_BYTES . ' bytes.'
                );
            }
            $merged[$locale][$rule] = $template;
        }

        return new self($merged);
    }

    /** @param array<string,array<string,string>> $extra locale => rule => template */
    public function withMany(array $extra): self
    {
        $catalog = $this;
        foreach ($extra as $locale => $rules) {
            $catalog = $catalog->with((string) $locale, is_array($rules) ? $rules : []);
        }

        return $catalog;
    }

    public function templateFor(string $locale, string $rule): ?string
    {
        foreach (self::localeChain($locale) as $candidate) {
            if (isset($this->templates[$candidate][$rule])) {
                return $this->templates[$candidate][$rule];
            }
        }

        return null;
    }

    /** @return list<string> exact → base → wildcard */
    public static function localeChain(string $locale): array
    {
        $locale = strtolower(trim($locale));
        $chain = [];
        if (self::isValidLocale($locale)) {
            $chain[] = $locale;
            $base = explode('_', $locale)[0];
            if ($base !== $locale) {
                $chain[] = $base;
            }
        }
        $chain[] = '*';

        // @infection-ignore-all UnwrapArrayUnique,UnwrapArrayValues — ekuivalen: $chain dibangun tanpa duplikat; unwrap tidak mengubah hasil
        return array_values(array_unique($chain));
    }

    private static function isValidLocale(string $locale): bool
    {
        return preg_match('/^[a-z]{2,8}(_[a-z0-9]{2,8})?$/i', $locale) === 1;
    }
}
