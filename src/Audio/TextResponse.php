<?php

declare(strict_types=1);

namespace Prism\Prism\Audio;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\ValueObjects\Usage;

/**
 * @implements Arrayable<string, mixed>
 */
readonly class TextResponse implements Arrayable
{
    public function __construct(
        public string $text,
        public ?Usage $usage = null,
        /** @var array<string,mixed> */
        public array $additionalContent = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'usage' => $this->usage?->toArray(),
            'additional_content' => $this->additionalContent,
        ];
    }
}
