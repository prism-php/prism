<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string,mixed>
 */
class MessagePartWithCitations implements Arrayable
{
    /**
     * @param  Citation[]  $citations
     */
    public function __construct(
        public readonly string $text,
        public readonly array $citations = [],
        public readonly ?int $startPosition = null,
        public readonly ?int $endPosition = null
    ) {}

    /**
     * @return array<string,mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'startPosition' => $this->startPosition,
            'endPosition' => $this->endPosition,
            'citations' => array_map(
                fn (Citation $citation): array => $citation->toArray(),
                $this->citations
            ),
        ], fn ($value): bool => $value !== null);
    }
}
