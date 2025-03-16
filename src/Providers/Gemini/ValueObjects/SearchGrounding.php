<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string,mixed>
 */
class SearchGrounding implements Arrayable
{
    public function __construct(
        public readonly string $title,
        public readonly string $uri,
        public readonly float $confidence
    ) {}

    /**
     * @return array<string,mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'uri' => $this->uri,
            'confidence' => $this->confidence,
        ];
    }
}
