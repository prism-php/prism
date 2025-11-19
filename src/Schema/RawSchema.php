<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Concerns\NullableSchema;
use Prism\Prism\Contracts\Schema;

class RawSchema implements Schema
{
    use NullableSchema;

    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public readonly string $name,
        public readonly array $schema
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function toArray(): array
    {
        return $this->schema;
    }
}
