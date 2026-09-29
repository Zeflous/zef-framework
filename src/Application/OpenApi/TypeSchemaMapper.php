<?php

declare(strict_types=1);

// ZEF Framework — Application layer (OpenAPI documentation module).
// Extracted from SchemaGenerator during the sonar-zero campaign.

namespace Zef\Framework\OpenApi;

/**
 * Maps reflection types onto OpenAPI Schemas.
 *
 * Union types become oneOf (nullable unions collapse into a nullable base);
 * intersection types become allOf; plain arrays emit an unconstrained map
 * as items. Class-typed members are routed back through the owning
 * generator so referenced DTOs land in components/schemas.
 */
final class TypeSchemaMapper
{
    public function __construct(
        private readonly SchemaGenerator $generator,
    ) {}

    public function map(\ReflectionType $type): Schema
    {
        if ($type instanceof \ReflectionNamedType) {
            $schema = $this->mapNamed($type);
        } elseif ($type instanceof \ReflectionUnionType) {
            $schema = $this->mapUnion($type);
        } elseif ($type instanceof \ReflectionIntersectionType) {
            $schema = $this->mapIntersection($type);
        } else {
            $schema = new Schema(type: SchemaType::String);
        }

        return $schema;
    }

    private function mapNamed(\ReflectionNamedType $type): Schema
    {
        $schema = $this->mapNamedType($type);

        if ($type->allowsNull()) {
            return $schema->asNullable();
        }

        return $schema;
    }

    private function mapUnion(\ReflectionUnionType $type): Schema
    {
        [$schemas, $nullable] = $this->collectUnionMembers($type);
        $schemas = $this->dedupeSchemas($schemas);

        if ($schemas === []) {
            return new Schema(type: SchemaType::String);
        }

        if (count($schemas) === 1) {
            return $this->singleMemberSchema($schemas[0], $nullable);
        }

        return new Schema(nullable: $nullable ? true : null, oneOf: $schemas);
    }

    /**
     * @return array{list<Schema>, bool}
     */
    private function collectUnionMembers(\ReflectionUnionType $type): array
    {
        $schemas = [];
        $nullable = false;

        foreach ($type->getTypes() as $member) {
            if (!$member instanceof \ReflectionNamedType) {
                continue;
            }

            if ($member->getName() === 'null') {
                $nullable = true;

                continue;
            }

            $schemas[] = $this->mapNamedType($member);
        }

        return [$schemas, $nullable];
    }

    private function singleMemberSchema(Schema $base, bool $nullable): Schema
    {
        if (!$nullable) {
            return $base;
        }

        return new Schema(
            type: $base->ref === null ? $base->type : SchemaType::Object,
            format: $base->format,
            ref: $base->ref,
            nullable: true,
        );
    }

    private function mapIntersection(\ReflectionIntersectionType $type): Schema
    {
        $schemas = [];

        foreach ($type->getTypes() as $member) {
            if ($member instanceof \ReflectionNamedType) {
                $schemas[] = $this->mapNamedType($member, allowClass: true);
            }
        }

        if ($schemas === []) {
            return new Schema(type: SchemaType::String);
        }

        return new Schema(allOf: $schemas);
    }

    private function mapNamedType(\ReflectionNamedType $type, bool $allowClass = true): Schema
    {
        $name = $type->getName();

        if (!$type->isBuiltin()) {
            if (!$allowClass) {
                return new Schema(type: SchemaType::String);
            }

            // class-typed properties reference the component schema; the
            // class itself is generated (and cached) so registeredSchemas()
            // can emit it under components/schemas.
            $this->generator->generateFromClass($name);

            return new Schema(ref: $this->generator->schemaRef($name));
        }

        return $this->mapBuiltin($name);
    }

    private function mapBuiltin(string $name): Schema
    {
        return match ($name) {
            'int' => new Schema(type: SchemaType::Integer),
            'float' => new Schema(type: SchemaType::Number, format: 'float'),
            'string' => new Schema(type: SchemaType::String),
            'bool' => new Schema(type: SchemaType::Boolean),
            'array' => new Schema(
                type: SchemaType::Array,
                items: new Schema(type: SchemaType::Object, additionalPropertiesAllowed: true),
            ),
            // mixed/object/callable/iterable and any exotic builtin carry no
            // useful OpenAPI primitive; document them as opaque strings.
            default => new Schema(type: SchemaType::String),
        };
    }

    /**
     * @param list<Schema> $schemas
     *
     * @return list<Schema>
     */
    private function dedupeSchemas(array $schemas): array
    {
        $seen = [];
        $out = [];

        foreach ($schemas as $schema) {
            $key = json_encode($schema->toArray(), JSON_THROW_ON_ERROR);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $schema;
        }

        return $out;
    }
}
