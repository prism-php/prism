<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  null|array<int, array<string, mixed>>  $reasonings
     * @return array<int, ToolCall>
     */
    public static function map(?array $toolCalls, ?array $reasonings = null): array
    {
        if ($toolCalls === null) {
            return [];
        }

        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'id'),
            resultId: data_get($toolCall, 'call_id'),
            name: data_get($toolCall, 'name'),
            arguments: data_get($toolCall, 'arguments'),
            reasoningId: data_get($reasonings, '0.id'),
            reasoningSummary: data_get($reasonings, '0.summary'),
        ), $toolCalls);
    }
}
