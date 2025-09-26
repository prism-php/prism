<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Concerns\NullableSchema;
use Prism\Prism\Contracts\Schema;

class StringSchema implements Schema
{
    use NullableSchema;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $nullable = false,
        public readonly ?string $pattern = null,
        public readonly ?string $format = null,
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
                ? $this->castToNullable('string')
                : 'string',
        ];

        if ($this->pattern !== null) {
            $schema['pattern'] = $this->pattern;
        }
        if ($this->format !== null) {
            $schema['format'] = $this->format;
        }

        return $schema;
    }
}
