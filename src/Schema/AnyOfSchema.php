<?php

declare(strict_types=1);

namespace Prism\Prism\Schema;

use Prism\Prism\Contracts\Schema;

class AnyOfSchema implements Schema
{
    /**
     * @param  Schema[]  $schemas  Array of schema instances to match any of
     * @param  string  $name  Optional name for the schema
     * @param  string  $description  Optional description
     */
    public function __construct(
        public readonly array $schemas,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
    ) {}

    public function name(): string
    {
        return $this->name ?? 'item';
    }

    public function toArray(): array
    {
        $result = [
            'anyOf' => array_map(fn (\Prism\Prism\Contracts\Schema $schema): array => $schema->toArray(), $this->schemas),
        ];
        if ($this->description !== null) {
            $result['description'] = $this->description;
        }
        if ($this->name !== null) {
            $result['name'] = $this->name;
        }

        return $result;
    }
}
