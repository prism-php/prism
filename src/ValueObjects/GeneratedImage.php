<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class GeneratedImage
{
    public function __construct(
        public ?string $url = null,
        public ?string $base64 = null,
        public ?string $revisedPrompt = null,
    ) {}

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function hasBase64(): bool
    {
        return $this->base64 !== null;
    }

    public function hasRevisedPrompt(): bool
    {
        return $this->revisedPrompt !== null;
    }
}
