<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

readonly class Chunk
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     */
    public function __construct(
        public string $text,
        public array $toolCalls = [],
        public array $toolResults = [],
        public ?FinishReason $finishReason = null,
        public ?Meta $meta = null,
        public ?string $content = null,
    ) {}
}
