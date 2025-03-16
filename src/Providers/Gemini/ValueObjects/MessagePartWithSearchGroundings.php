<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string,mixed>
 */
class MessagePartWithSearchGroundings implements Arrayable
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

    /**
     * @return array<string,mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'startIndex' => $this->startIndex,
            'endIndex' => $this->endIndex,
            'groundings' => array_map(
                fn (SearchGrounding $grounding): array => $grounding->toArray(),
                $this->groundings
            ),
        ];
    }
}
