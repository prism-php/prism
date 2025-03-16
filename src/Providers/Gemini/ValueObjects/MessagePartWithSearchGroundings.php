<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

class MessagePartWithSearchGroundings
{
    /**
     * @param  SearchGrounding[]  $groundings
     */
    public function __construct(
        public readonly string $text,
        public readonly int $startIndex,
        public readonly int $endIndex,
        public readonly array $groundings = []
    ) {}
}
