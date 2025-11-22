<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

readonly class Response
{
    /**
     * @param  Collection<int, Step>  $steps
     * @param  array<mixed>  $structured
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public Collection $steps,
        public string $text,
        public ?array $structured,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
        public array $toolCalls = [],
        public array $toolResults = [],
        public array $additionalContent = []
    ) {}
}
