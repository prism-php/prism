<?php

namespace Prism\Prism\Testing;

use Illuminate\Support\Collection;
use Prism\Prism\Concerns\HasFluentAttributes;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * @method self withSteps(Collection<int, Step> $steps)
 * @method self withText(string $text)
 * @method self withFinishReason(FinishReason $finishReason)
 * @method self withToolCalls(ToolCall[] $toolCalls)
 * @method self withToolResults(ToolResult[] $toolResults)
 * @method self withUsage(Usage $usage)
 * @method self withMeta(Meta $meta)
 * @method self withMessages(Collection<int, Message> $messages)
 * @method self withAdditionalContent(array<string,mixed> $additionalContent)
 */
readonly class TextResponseFake extends Response
{
    use HasFluentAttributes;

    public static function make(): self
    {
        return new self(
            steps: collect([]),
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: collect([]),
            additionalContent: [],
        );
    }
}
