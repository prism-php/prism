<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Concerns\NullableSchema;
use Prism\Prism\Contracts\Schema;

class AnyOfSchema implements Schema
{
    use NullableSchema;

    /**
     * @param  array<Schema>  $schemas  Array of schema instances to match any of
     * @param  string|null  $name  Optional name for the schema
     * @param  string|null  $description  Optional description for the schema
     * @param  bool  $nullable  Whether the schema can be null
     */
    public function __construct(
        public readonly array $schemas,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly bool $nullable = false,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name ?? 'item';
    }

    #[\Override]
    public function toArray(): array
    {
        // Validate that all nested schemas are valid according to OpenAI requirements
        $validatedSchemas = array_map(
            fn (Schema $schema): array => $this->validateNestedSchema($schema->toArray()),
            $this->schemas
        );

        $schema = [
            'anyOf' => $validatedSchemas,
        ];

        // Add description only if provided
        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }

        // If nullable, add null as one of the anyOf options
        if ($this->nullable) {
            $schema['anyOf'][] = ['type' => 'null'];
        }

        return $schema;
    }

    /**
     * Validate that nested schema conforms to OpenAI's subset requirements.
     *
     * @param  array<string, mixed>  $schemaArray
     * @return array<string, mixed>
     */
    protected function validateNestedSchema(array $schemaArray): array
    {
        // For anyOf, nested schemas must each be a valid JSON schema per OpenAI's subset
        // This includes ensuring they have proper structure and don't contain unsupported features

        // Ensure the schema has a type (required by OpenAI)
        if (! isset($schemaArray['type']) && ! isset($schemaArray['anyOf']) && ! isset($schemaArray['oneOf'])) {
            throw new \InvalidArgumentException(
                'Each nested schema in anyOf must have a "type" or be a composition schema (anyOf/oneOf)'
            );
        }

        // Remove unsupported properties that might cause issues with OpenAI
        $unsupportedKeys = ['examples', 'default', 'if', 'then', 'else', 'not'];
        foreach ($unsupportedKeys as $key) {
            unset($schemaArray[$key]);
        }

        return $schemaArray;
    }
}
