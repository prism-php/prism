<?php

declare(strict_types=1);

namespace Prism\Prism\Text;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

readonly class Step
{
    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  Message[]  $messages
     * @param  SystemMessage[]  $systemPrompts
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public string $text,
        public FinishReason $finishReason,
        public array $toolCalls,
        public array $toolResults,
        public Usage $usage,
        public Meta $meta,
        public array $messages,
        public array $systemPrompts,
        public array $additionalContent = []
    ) {}
}
