<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Media;

/**
 * @deprecated Use `Document::fromFileId()` instead.
 */
readonly class OpenAIFile
{
    public function __construct(public string $fileId) {}
}
