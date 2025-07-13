<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Media;

readonly class OpenAIFile
{
    public function __construct(public string $fileId) {}
}
