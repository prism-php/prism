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
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }
}
