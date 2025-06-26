<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

readonly class ToolCallChunk
{
    /**
     * @param  array<int, \Prism\Prism\ValueObjects\ToolCall>  $toolCalls
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public array $toolCalls,
        public array $additionalContent = []
    ) {}
}
