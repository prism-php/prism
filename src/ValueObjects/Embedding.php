<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
class Embedding implements Arrayable
{
    /**
     * @param  array<int, int|string|float>  $embedding
     */
    public function __construct(
        public array $embedding
    ) {}

    /**
     * @param  array<int, int|string|float>  $embedding
     */
    public static function fromArray(array $embedding): self
    {
        return new self(embedding: $embedding);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'embedding' => $this->embedding,
        ];
    }
}
