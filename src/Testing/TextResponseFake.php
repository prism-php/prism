<?php

namespace Prism\Prism\Testing;

use Illuminate\Support\Collection;
use Prism\Prism\Concerns\HasFluentAttributes;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @method self withSteps(Collection $steps)
 * @method self withResponseMessages(Collection $responseMessages)
 * @method self withText(string $text)
 * @method self withFinishReason(FinishReason $finishReason)
 * @method self withToolCalls(array $toolCalls)
 * @method self withToolResults(array $toolResults)
 * @method self withUsage(Usage $usage)
 * @method self withMeta(Meta $meta)
 * @method self withMessages(Collection $messages)
 * @method self withAdditionalContent(array $additionalContent)
 */
readonly class TextResponseFake extends Response
{
    use HasFluentAttributes;

    public static function make(): self
    {
        return new self(
            steps: collect([]),
            responseMessages: collect([]),
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
