<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Concerns;

use Prism\Prism\ValueObjects\ToolCall;

trait MapsToolCalls
{
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id', ''),
            name: data_get($toolCall, 'function.name'),
            arguments: data_get($toolCall, 'function.arguments'),
        ), $toolCalls);
    }
}
