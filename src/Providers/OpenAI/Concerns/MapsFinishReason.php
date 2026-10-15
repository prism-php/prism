<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\OpenAI\Maps\FinishReasonMap;
use Prism\Prism\Providers\OpenAI\Support\OutputItems;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $topLevelStatus = data_get($data, 'status', '');

        if ($topLevelStatus === 'incomplete') {
            return match (data_get($data, 'incomplete_details.reason')) {
                'content_filter' => FinishReason::ContentFilter,
                'steered' => FinishReason::Other,
                default => FinishReason::Length,
            };
        }

        if ($topLevelStatus === 'failed') {
            return FinishReason::Error;
        }

        /** @var array<array-key, mixed> $output */
        $output = data_get($data, 'output') ?? [];

        if (OutputItems::refusal($output) !== null) {
            return FinishReason::Refusal;
        }

        if ($topLevelStatus === 'completed' && OutputItems::hasFunctionCall($output)) {
            return FinishReason::ToolCalls;
        }

        return FinishReasonMap::map(
            data_get($data, 'output.{last}.status', ''),
            data_get($data, 'output.{last}.type', ''),
        );
    }

    /**
     * The response ran out of output tokens (as opposed to stopping for another
     * incomplete reason such as max_messages, which returns a Length response).
     *
     * @param  array<string, mixed>  $data
     */
    protected function exceededMaxOutputTokens(array $data, FinishReason $finishReason): bool
    {
        return $finishReason === FinishReason::Length
            && data_get($data, 'incomplete_details.reason') !== 'max_messages';
    }
}
