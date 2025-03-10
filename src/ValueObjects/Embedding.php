<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class Embedding
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
}
