<?php

declare(strict_types=1);

// ZEF Framework — Application layer (OpenAPI documentation module).
// Extracted from SchemaGenerator during the sonar-zero campaign.

namespace Zef\Framework\OpenApi;

use Zef\Framework\Validation\FieldRules;
use Zef\Framework\Validation\Validator;

/**
 * Derives OpenAPI object schemas from the v2.8.0 validation engine: every
 * declared field becomes a property with constraints inferred from the
 * registered rule chain, plus the required/nullable bookkeeping.
 */
final class FieldRulesSchemaMapper
{
    public function mapValidator(Validator $validator): Schema
    {
        $properties = [];
        $required = [];

        foreach ($validator->fieldNames() as $fieldName) {
            $rules = $validator->field($fieldName);
            $properties[$fieldName] = $this->mapField($rules);

            if ($this->fieldIsRequired($rules)) {
                $required[] = $fieldName;
            }
        }

        return new Schema(
            type: SchemaType::Object,
            required: $required,
            properties: $properties,
        );
    }

    public function mapField(FieldRules $rules): Schema
    {
        $constraints = new FieldSchemaConstraints();

        foreach ($rules->definitions() as $definition) {
            $constraints->apply($definition);
        }

        return $constraints->toSchema();
    }

    private function fieldIsRequired(FieldRules $rules): bool
    {
        // A required rule disabled by nullable() (skipNull) accepts absent and
        // null values, so the field must not be advertised as required — the
        // runtime gate would 400 requests the validator itself accepts (#316).
        return array_any(
            $rules->definitions(),
            fn (array $definition): bool => $definition['rule'] === 'required' && $definition['nullable'] === false,
        );
    }
}
