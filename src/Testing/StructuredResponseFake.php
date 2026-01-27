<?php

namespace Prism\Prism\Testing;

use Illuminate\Support\Collection;
use Prism\Prism\Concerns\HasFluentAttributes;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * @method self withSteps(Collection<int, Step> $steps)
 * @method self withText(string $text)
 * @method self withStructured(array<mixed> $structured)
 * @method self withFinishReason(FinishReason $finishReason)
 * @method self withUsage(Usage $usage)
 * @method self withMeta(Meta $meta)
 * @method self withAdditionalContent(array<string,mixed> $additionalContent)
 */
readonly class StructuredResponseFake extends Response
{
    use HasFluentAttributes;

    public static function make(): self
    {
        return new self(
            steps: collect([]),
            text: '',
            structured: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake'),
            additionalContent: [],
        );
    }
}
