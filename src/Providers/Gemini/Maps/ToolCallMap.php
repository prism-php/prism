<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\ValueObjects\ToolCall;

class ToolCallMap
{
    /**
     * @param  array<array<string, mixed>>  $toolCalls
     * @return array<ToolCall>
     */
    public static function map(array $toolCalls): array
    {
        if ($toolCalls === []) {
            return [];
        }

        $filteredToolCalls = array_filter($toolCalls, fn (array $item): bool => isset($item['functionCall']));

        return array_map(fn (array $toolCall): ToolCall => new ToolCall(
            id: data_get($toolCall, 'functionCall.name'),
            name: data_get($toolCall, 'functionCall.name'),
            arguments: data_get($toolCall, 'functionCall.args'),
            reasoningId: data_get($toolCall, 'thoughtSignature'),
        ), $filteredToolCalls);
    }
}
