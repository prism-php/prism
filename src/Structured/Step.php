<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

readonly class Step
{
    /**
     * @param  Message[]  $messages
     * @param  SystemMessage[]  $systemPrompts
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public readonly string $text,
        public readonly FinishReason $finishReason,
        public readonly Usage $usage,
        public readonly Meta $meta,
        public readonly array $messages,
        public readonly array $systemPrompts,
        public readonly array $additionalContent = []
    ) {}
}
