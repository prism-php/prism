<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

class GeneratedImage extends Media\Media
{
    public ?string $revisedPrompt;

    public function __construct(?string $url = null, ?string $base64 = null, ?string $revisedPrompt = null, ?string $mimeType = null)
    {
        parent::__construct($url, $base64, $mimeType);
        $this->revisedPrompt = $revisedPrompt;
    }

    public function hasRevisedPrompt(): bool
    {
        return $this->revisedPrompt !== null;
    }
}
