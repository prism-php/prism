<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Prism\Concerns\HasProviderOptions;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * @implements Arrayable<string, mixed>
 */
class ToolResultMessage implements Arrayable, Message
{
    use HasProviderOptions;

    /**
     * @param  ToolResult[]  $toolResults
     */
    public function __construct(
        public readonly array $toolResults
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => 'tool_result',
            'tool_results' => array_map(fn (ToolResult $toolResult): array => $toolResult->toArray(), $this->toolResults),
        ];
    }
}
