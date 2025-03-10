<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\ValueObjects;

class Citation
{
    public function __construct(
        public readonly string $type,
        public readonly string $citedText,
        public readonly int $startIndex,
        public readonly int $endIndex,
        public readonly int $documentIndex,
        public readonly ?string $documentTitle = null
    ) {}
}
