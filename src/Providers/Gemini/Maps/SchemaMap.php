<?php

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Contracts\HasSchemaType;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\RawSchema;

class SchemaMap
{
    public function __construct(
        private readonly Schema $schema,
    ) {}

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        $schemaArray = $this->schema->toArray();

        // Remove unsupported fields
        unset($schemaArray['additionalProperties'], $schemaArray['name']);

        if ($this->schema instanceof RawSchema) {
            return $schemaArray;
        }

        // AnyOfSchema: recursively process nested schemas to remove unsupported fields
        if ($this->schema instanceof AnyOfSchema) {
            $processedSchemas = array_map(
                fn (Schema $schema): array => (new self($schema))->toArray(),
                $this->schema->schemas
            );

            // Preserve nullable marker
            if (! empty($schemaArray['anyOf'])) {
                $lastElement = end($schemaArray['anyOf']);
                if (isset($lastElement['type']) && $lastElement['type'] === 'null') {
                    $processedSchemas[] = $lastElement;
                }
            }

            $schemaArray['anyOf'] = $processedSchemas;

            return $schemaArray;
        }

        return array_merge(
            array_filter([
                ...$schemaArray,
                'type' => $this->mapType(),
            ]),
            array_filter([
                'items' => property_exists($this->schema, 'items') && $this->schema->items
                    ? (new self($this->schema->items))->toArray()
                    : null,
                'properties' => $this->schema instanceof ObjectSchema && property_exists($this->schema, 'properties')
                    ? array_reduce($this->schema->properties, function (array $carry, Schema $property): array {
                        // Use property name as the key, but do NOT include "name" inside the value
                        $carry[$property->name()] = (new self($property))->toArray();

                        return $carry;
                    }, [])
                    : null,
                'nullable' => property_exists($this->schema, 'nullable') && $this->schema->nullable
                    ? true
                    : null,
            ])
        );
    }

    protected function mapType(): string
    {
        if ($this->schema instanceof ArraySchema) {
            return 'array';
        }
        if ($this->schema instanceof BooleanSchema) {
            return 'boolean';
        }
        if ($this->schema instanceof NumberSchema) {
            return 'number';
        }
        if ($this->schema instanceof ObjectSchema) {
            return 'object';
        }
        if ($this->schema instanceof HasSchemaType) {
            return $this->schema->schemaType();
        }

        return 'string';
    }
}
