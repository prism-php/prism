<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Concerns\NullableSchema;
use Prism\Prism\Contracts\Schema;

class NumberSchema implements Schema
{
    use NullableSchema;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly bool $nullable = false,
        public readonly ?float $multipleOf = null,
        public readonly ?float $maximum = null,
        public readonly ?float $exclusiveMaximum = null,
        public readonly ?float $minimum = null,
        public readonly ?float $exclusiveMinimum = null,
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
                ? $this->castToNullable('number')
                : 'number',
        ];

        if ($this->multipleOf !== null) {
            $schema['multipleOf'] = $this->multipleOf;
        }
        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }
        if ($this->exclusiveMaximum !== null) {
            $schema['exclusiveMaximum'] = $this->exclusiveMaximum;
        }
        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }
        if ($this->exclusiveMinimum !== null) {
            $schema['exclusiveMinimum'] = $this->exclusiveMinimum;
        }

        return $schema;
    }
}
