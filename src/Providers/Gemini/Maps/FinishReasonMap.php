<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Gemini\Maps;

use Prism\Prism\Enums\FinishReason;

class FinishReasonMap
{
    public static function map(?string $reason, bool $toolCall = false): FinishReason
    {
        return match ($reason) {
            'STOP' => $toolCall ? FinishReason::ToolCalls : FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            // The per-request token limit was reached and the answer is NOT
            // finished. Under Other a cut-off answer looked complete (Particle-Academy/prism#82).
            'CONTINUATION' => FinishReason::Length,
            'SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL' => FinishReason::ContentFilter,
            'RECITATION' => FinishReason::ContentFilter,
            'LANGUAGE' => FinishReason::Other,
            'FINISH_REASON_UNSPECIFIED', 'OTHER', null => FinishReason::Other,
            default => FinishReason::Other,
        };
    }
}
