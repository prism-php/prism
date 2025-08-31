<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;

class Citation
{
    public function __construct(
        public CitationSourceType $sourceType,
        /** String if sourceType is Url, otherwise integer. */
        public string|int $source,
        /** The text from the source material relied upon */
        public ?string $sourceText = null,
        /** The title of the source material */
        public ?string $sourceTitle = null,
        /** Identifies what the $sourceStartIndex and $sourceEndIndex relate to (pages, chars, etc.) */
        public ?CitationSourcePositionType $sourcePositionType = null,
        public ?int $sourceStartIndex = null,
        public ?int $sourceEndIndex = null,
        /** @var array<string,mixed> */
        public array $additionalContent = []
    ) {}
}
