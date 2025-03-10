<?php

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Contracts\Schema;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;

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
        return array_merge([
            ...array_filter([
                ...$this->schema->toArray(),
                'type' => $this->mapType(),
                'additionalProperties' => null,
            ]),
        ], array_filter([
            'items' => property_exists($this->schema, 'items') ?
                (new self($this->schema->items))->toArray() :
                null,
            'properties' => property_exists($this->schema, 'properties') ?
                array_reduce($this->schema->properties, fn (array $carry, Schema $property) => [
                    ...$carry,
                    $property->name() => (new self($property))->toArray(),
                ], []) :
                null,
            'nullable' => property_exists($this->schema, 'nullable')
                ? $this->schema->nullable
                : null,
        ]));
    }

    protected function mapType(): string
    {
        return match ($this->schema::class) {
            ArraySchema::class => 'array',
            BooleanSchema::class => 'boolean',
            NumberSchema::class => 'number',
            ObjectSchema::class => 'object',
            default => 'string',
        };
    }
}
