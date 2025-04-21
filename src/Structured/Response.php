<?php

declare(strict_types=1);

namespace Prism\Prism\Structured;

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

readonly class Response
{
    /**
     * @param  Collection<int, Step>  $steps
     * @param  Collection<int, Message>  $responseMessages
     * @param  array<mixed>  $structured
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public Collection $steps,
        public Collection $responseMessages,
        public string $text,
        public ?array $structured,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
        public array $additionalContent = []
    ) {}
}
