<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

readonly class GeneratedAudio
{
    public function __construct(
        public ?string $base64 = null,
        public ?string $type = null,
    ) {}

    public function hasBase64(): bool
    {
        return $this->base64 !== null;
    }

    public function getMimeType(): ?string
    {
        return $this->type;
    }
}
