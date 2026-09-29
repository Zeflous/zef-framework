<?php

declare(strict_types=1);

// ZEF Framework — Application layer (OpenAPI documentation module).
// Extracted from RouteMetadataAccumulator (php:S1200): builds the media-type
// content maps and inline schemas for #[Parameter], #[RequestBody] and
// #[Response] attributes, behaviour-identical to the former inline code.

namespace Zef\Framework\OpenApi;

/**
 * @internal builds the OpenAPI content maps the route metadata accumulator
 * attaches to parameters, request bodies and responses.
 */
final class SchemaContentBuilder
{
    /**
     * Content map for a #[RequestBody] attribute: the declared schema (when
     * it resolves to a class or enum) is referenced from components; an
     * unresolved body falls back to a bare object schema under JSON.
     *
     * @return array<string, Schema>
     */
    public function requestBodyContent(Attribute\RequestBody $meta, SchemaGenerator $generator): array
    {
        $content = $this->referencedContent($meta->schema, $meta->mediaType->value, $generator);
        if ($content === []) {
            return [MediaType::Json->value => new Schema(type: SchemaType::Object)];
        }

        return $content;
    }

    /**
     * Content map for a #[Response] attribute: empty when the declared
     * schema does not resolve (a response may legitimately have no body).
     *
     * @return array<string, Schema>
     */
    public function responseContent(Attribute\Response $meta, SchemaGenerator $generator): array
    {
        return $this->referencedContent($meta->schema, $meta->mediaType, $generator);
    }

    /** Inline schema for a #[Parameter] attribute (type/format pair). */
    public function parameterSchema(Attribute\Parameter $meta): Schema
    {
        return new Schema(type: $meta->type, format: $meta->format);
    }

    /**
     * @return array<string, Schema>
     */
    private function referencedContent(?string $schemaClass, string $mediaType, SchemaGenerator $generator): array
    {
        if ($schemaClass === null || (!class_exists($schemaClass) && !enum_exists($schemaClass))) {
            return [];
        }
        $generator->generateFromClass($schemaClass);

        return [$mediaType => new Schema(ref: '#/components/schemas/' . $generator->schemaNameFor($schemaClass))];
    }
}
