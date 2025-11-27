<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  null|array<int, array<string, mixed>>  $reasoningItems
     * @return array<int, ToolCall>
     */
    public static function map(?array $toolCalls, ?array $reasoningItems = null): array
    {
        if ($toolCalls === null) {
            return [];
        }

        [$reasoningId, $reasoningSummary] = self::resolveReasoningItem($reasoningItems);

        return collect($toolCalls)->map(
            fn (array $toolCall): ToolCall => new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: data_get($toolCall, 'arguments'),
                resultId: data_get($toolCall, 'call_id'),
                reasoningId: $reasoningId,
                reasoningSummary: $reasoningSummary,
            ),
        )
            ->toArray();
    }

    protected static function resolveReasoningItem(?array $reasoningItems): array
    {
        $reasoningItems = array_reverse($reasoningItems ?? []);

        return [
            data_get($reasoningItems, '0.id'),
            data_get($reasoningItems, '0.summary'),
        ];
    }
}
