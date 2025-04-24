<?php

declare(strict_types=1);

namespace Prism\Prism\Testing;

use Prism\Prism\Concerns\HasFluentAttributes;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @method self withText(string $text)
 * @method self withFinishReason(FinishReason $finishReason)
 * @method self withUsage(Usage $usage)
 * @method self withMeta(Meta $meta)
 * @method self withMessages(array $messages)
 * @method self withSystemPrompts(array $systemPrompts)
 * @method self withAdditionalContent(array $additionalContent)
 */
readonly class StructuredStepFake extends Step
{
    use HasFluentAttributes;

    public static function make(): self
    {
        return new self(
            text: '',
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            messages: [],
            systemPrompts: [],
            additionalContent: [],
        );
    }
}
