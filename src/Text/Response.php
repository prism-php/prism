<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

readonly class Response
{
    /**
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Message>  $responseMessages
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  Collection<int, Message>  $messages
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public Collection $steps,
        public Collection $responseMessages,
        public string $text,
        public FinishReason $finishReason,
        public array $toolCalls,
        public array $toolResults,
        public Usage $usage,
        public Meta $meta,
        public Collection $messages,
        public array $additionalContent = []
    ) {}
}
