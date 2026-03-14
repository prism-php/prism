<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Illuminate\Support\Arr;
use Prism\Prism\Concerns\NullableSchema;
use Prism\Prism\Contracts\Schema;

class ObjectSchema implements Schema
{
    use NullableSchema;

    /**
     * @param  array<int, Schema>  $properties
     * @param  array<int, string>  $requiredFields
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $requiredFields = [],
        public readonly bool $allowAdditionalProperties = false,
        public readonly bool $nullable = false,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function toArray(): array
    {
        $properties = $this->propertiesArray();

        return Arr::whereNotNull([
            'description' => $this->description,
            'type' => $this->nullable
                ? $this->castToNullable('object')
                : 'object',
            'properties' => $properties === [] ? null : $properties,
            'required' => $this->requiredFields,
            'additionalProperties' => $this->allowAdditionalProperties,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function propertiesArray(): array
    {
        return collect($this->properties)
            ->keyBy(fn (Schema $parameter): string => $parameter->name())
            ->map(fn (Schema $parameter): array => $parameter->toArray())
            ->toArray();
    }
}
