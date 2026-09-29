<?php

declare(strict_types=1);

// ZEF Framework — Application layer (OpenAPI documentation module).
// Extracted from SchemaGenerator during the sonar-zero campaign.

namespace Zef\Framework\OpenApi;

use Zef\Framework\OpenApi\Attribute\Property;

/**
 * Builds the object/enum Schema for a concrete class or enum.
 *
 * Attribute metadata (#[Schema], #[Property]) wins over native reflection
 * types; unannotated properties fall back to their PHP type. Class-typed
 * properties and #[Property(ref)] references resolve through the owning
 * generator so recursive DTO graphs collapse into #/components/schemas
 * references instead of recursing forever.
 */
final class ClassSchemaBuilder
{
    public function __construct(
        private readonly SchemaGenerator $generator,
    ) {}

    /**
     * @param class-string $className
     */
    public function build(string $className): Schema
    {
        $reflection = new \ReflectionClass($className);

        if (is_a($className, SchemaDefinitionInterface::class, true)) {
            return $className::openApiSchema();
        }

        if ($reflection->isEnum()) {
            return $this->buildEnumSchema($reflection);
        }

        return $this->buildObjectSchema($reflection);
    }

    /** @param \ReflectionClass<object> $reflection */
    private function buildObjectSchema(\ReflectionClass $reflection): Schema
    {
        $classMeta = $this->classSchemaAttribute($reflection);
        [$properties, $required] = $this->collectProperties($reflection);

        return new Schema(
            type: SchemaType::Object,
            description: $this->classDescription($classMeta),
            title: $this->classTitle($classMeta, $reflection),
            deprecated: $this->classDeprecated($classMeta),
            required: $required,
            properties: $properties,
        );
    }

    /** @param \ReflectionClass<object> $reflection */
    private function buildEnumSchema(\ReflectionClass $reflection): Schema
    {
        $shortName = $reflection->getShortName();
        $values = [];
        $isIntBacked = false;

        foreach ($reflection->getConstants() as $constant) {
            if ($constant instanceof \BackedEnum) {
                $values[] = $constant->value;

                if (is_int($constant->value)) {
                    $isIntBacked = true;
                }
            } elseif ($constant instanceof \UnitEnum) {
                $values[] = $constant->name;
            }
            // Pure constants never appear here for real enums; kept as a
            // defensive no-op so unexpected shapes cannot crash builds.
        }

        return new Schema(
            type: $isIntBacked ? SchemaType::Integer : SchemaType::String,
            title: $shortName,
            enum: $values !== [] ? $values : null,
        );
    }

    /**
     * Collects the object properties and the required-field list.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return array{array<string, Schema>, list<string>}
     */
    private function collectProperties(\ReflectionClass $reflection): array
    {
        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $meta = $this->propertyAttribute($property);
            $type = $property->getType();

            if ($this->propertyIsRequired($property, $type, $meta)) {
                $required[] = $property->getName();
            }

            $properties[$property->getName()] = $this->propertySchema($type, $meta);
        }

        return [$properties, $required];
    }

    private function propertySchema(?\ReflectionType $type, ?Property $meta): Schema
    {
        if ($meta instanceof Property && $meta->ref !== null) {
            $schema = $this->resolveRef($meta->ref);
        } elseif ($type instanceof \ReflectionType) {
            $schema = $this->generator->generateFromType($type);
        } elseif ($meta instanceof Property) {
            $schema = new Schema(type: $meta->type);
        } else {
            $schema = new Schema(type: SchemaType::String);
        }

        if ($meta instanceof Property) {
            return $this->applyPropertyMeta($schema, $meta);
        }

        return $schema;
    }

    private function propertyIsRequired(
        \ReflectionProperty $property,
        ?\ReflectionType $type,
        ?Property $meta,
    ): bool {
        $explicitlyRequired = $meta instanceof Property && $meta->required === true;
        $allowsNull = $type instanceof \ReflectionType && $type->allowsNull();
        $metaAllowsNullable = $meta instanceof Property && $meta->nullable;
        $implicitlyRequired = !$allowsNull && !$property->hasDefaultValue() && !$metaAllowsNullable;

        return $explicitlyRequired || $implicitlyRequired;
    }

    private function applyPropertyMeta(Schema $schema, Property $meta): Schema
    {
        if ($schema->ref !== null) {
            return $schema;
        }

        return new Schema(
            type: $meta->type !== SchemaType::String ? $meta->type : $schema->type,
            format: $meta->format ?? $schema->format,
            description: $meta->description !== '' ? $meta->description : $schema->description,
            nullable: $meta->nullable ? true : $schema->nullable,
            readOnly: $meta->readOnly || $schema->readOnly,
            writeOnly: $meta->writeOnly || $schema->writeOnly,
            deprecated: $meta->deprecated ?? $schema->deprecated,
            minLength: $meta->minLength ?? $schema->minLength,
            maxLength: $meta->maxLength ?? $schema->maxLength,
            pattern: $meta->pattern ?? $schema->pattern,
            minimum: $meta->minimum ?? $schema->minimum,
            maximum: $meta->maximum ?? $schema->maximum,
            minItems: $meta->minItems ?? $schema->minItems,
            maxItems: $meta->maxItems ?? $schema->maxItems,
            uniqueItems: $meta->uniqueItems ?? $schema->uniqueItems,
            items: $this->itemsFromMeta($meta) ?? $schema->items,
            additionalProperties: $schema->additionalProperties,
            additionalPropertiesAllowed: $schema->additionalPropertiesAllowed,
            default: $meta->default ?? $schema->default,
            example: $meta->example ?? $schema->example,
            enum: $meta->enum ?? $schema->enum,
        );
    }

    private function itemsFromMeta(Property $meta): ?Schema
    {
        if ($meta->itemsRef !== null) {
            return $this->resolveRef($meta->itemsRef);
        }

        if ($meta->itemsType instanceof SchemaType) {
            return new Schema(type: $meta->itemsType);
        }

        return null;
    }

    /** "User" -> #/components/schemas/User; class-string -> generated ref. */
    private function resolveRef(string $ref): Schema
    {
        if (class_exists($ref) || enum_exists($ref)) {
            $this->generator->generateFromClass($ref);

            return new Schema(ref: $this->generator->schemaRef($ref));
        }

        return new Schema(ref: '#/components/schemas/' . $ref);
    }

    /** @param \ReflectionClass<object> $reflection */
    private function classSchemaAttribute(\ReflectionClass $reflection): ?Attribute\Schema
    {
        $attribute = $reflection->getAttributes(Attribute\Schema::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return $attribute !== null ? $attribute->newInstance() : null;
    }

    private function propertyAttribute(\ReflectionProperty $property): ?Property
    {
        $attribute = $property->getAttributes(Property::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return $attribute !== null ? $attribute->newInstance() : null;
    }

    private function classDescription(?Attribute\Schema $meta): string
    {
        return $meta instanceof Attribute\Schema ? $meta->description : '';
    }

    /** @param \ReflectionClass<object> $reflection */
    private function classTitle(?Attribute\Schema $meta, \ReflectionClass $reflection): string
    {
        if ($meta instanceof Attribute\Schema && $meta->name !== null) {
            return $meta->name;
        }

        return $reflection->getShortName();
    }

    private function classDeprecated(?Attribute\Schema $meta): ?bool
    {
        return $meta instanceof Attribute\Schema && $meta->deprecated ? true : null;
    }
}
