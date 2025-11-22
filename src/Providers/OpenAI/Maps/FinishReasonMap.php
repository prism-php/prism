<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\Enums\FinishReason;

class FinishReasonMap
{
    public static function map(string $status, ?string $type = null): FinishReason
    {
        return match ($status) {
            'incomplete' => FinishReason::Length,
            'length' => FinishReason::Length,
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => str_ends_with((string) $type, '_call') ? FinishReason::ToolCalls : FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }
}
