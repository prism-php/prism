<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class GeneratedImage
{
    public function __construct(
        public ?string $url = null,
        public ?string $b64Json = null,
        public ?string $revisedPrompt = null,
    ) {}

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function hasB64Json(): bool
    {
        return $this->b64Json !== null;
    }

    public function hasRevisedPrompt(): bool
    {
        return $this->revisedPrompt !== null;
    }
}
