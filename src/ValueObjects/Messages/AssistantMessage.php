<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Prism\Prism\Concerns\HasProviderMeta;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolCall;

class AssistantMessage implements Message
{
    use HasProviderMeta;

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  array<string,mixed>  $additionalContent
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly array $additionalContent = []
    ) {}
}
