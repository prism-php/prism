<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Concerns\NullableSchema;
use Prism\Prism\Contracts\Schema;

class ArraySchema implements Schema
{
    use NullableSchema;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly Schema $items,
        public readonly bool $nullable = false,
        public readonly ?int $minItems = null,
        public readonly ?int $maxItems = null,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function toArray(): array
    {
        $schema = [
            'description' => $this->description,
            'type' => $this->nullable
                ? $this->castToNullable('array')
                : 'array',
            'items' => $this->items->toArray(),
        ];

        if ($this->minItems !== null) {
            $schema['minItems'] = $this->minItems;
        }
        if ($this->maxItems !== null) {
            $schema['maxItems'] = $this->maxItems;
        }

        return $schema;
    }
}
