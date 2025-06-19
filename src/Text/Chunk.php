<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

readonly class Chunk
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public string $text,
        public array $toolCalls = [],
        public array $toolResults = [],
        public ?FinishReason $finishReason = null,
        public ?Meta $meta = null,
        public ?Usage $usage = null,
        public array $additionalContent = [],
        public ChunkType $chunkType = ChunkType::Text
    ) {}
}
