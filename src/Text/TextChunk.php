<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Prism\Prism\Enums\FinishReason;

readonly class TextChunk
{
    /**
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public string $text,
        public ?FinishReason $finishReason = null,
        public array $additionalContent = []
    ) {}
}
