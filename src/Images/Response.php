<?php

declare(strict_types=1);

namespace Prism\Prism\Images;

use Prism\Prism\ValueObjects\GeneratedImage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

readonly class Response
{
    /**
     * @param  GeneratedImage[]  $images
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public array $images,
        public Usage $usage,
        public Meta $meta,
        public array $additionalContent = []
    ) {}

    public function firstImage(): ?GeneratedImage
    {
        return $this->images[0] ?? null;
    }

    public function hasImages(): bool
    {
        return $this->images !== [];
    }

    public function imageCount(): int
    {
        return count($this->images);
    }
}
