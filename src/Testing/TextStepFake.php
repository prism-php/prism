<?php

declare(strict_types=1);

namespace Prism\Prism\Testing;

use Prism\Prism\Concerns\HasFluentAttributes;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * @method self withText(string $text)
 * @method self withFinishReason(FinishReason $finishReason)
 * @method self withToolCalls(ToolCall[] $toolCalls)
 * @method self withToolResults(ToolResult[] $toolResults)
 * @method self withProviderToolCalls(ProviderToolCall[] $providerToolCalls)
 * @method self withUsage(Usage $usage)
 * @method self withMeta(Meta $meta)
 * @method self withMessages(Message[] $messages)
 * @method self withSystemPrompts(SystemMessage[] $systemPrompts)
 * @method self withAdditionalContent(array<string,mixed> $additionalContent)
 */
readonly class TextStepFake extends Step
{
    use HasFluentAttributes;

    public static function make(): self
    {
        return new self(
            text: '',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            providerToolCalls: [],
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: [],
            systemPrompts: [],
            additionalContent: [],
        );
    }
}
