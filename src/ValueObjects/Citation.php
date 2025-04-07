<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string,mixed>
 */
class Citation implements Arrayable
{
    public function __construct(
        public readonly string $text,
        public readonly int $startPosition,
        public readonly int $endPosition,
        public readonly int $sourceIndex,
        public readonly ?string $sourceTitle = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?float $confidence = null,
        public readonly ?string $type = null
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
            'sourceIndex' => $this->sourceIndex,
            'sourceTitle' => $this->sourceTitle,
            'sourceUrl' => $this->sourceUrl,
            'confidence' => $this->confidence,
            'type' => $this->type,
        ], fn ($value): bool => $value !== null);
    }
}
