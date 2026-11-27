<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\Enums\FinishReason;

trait ExtractsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasFinishReason(array $data): bool
    {
        return data_get($data, 'status') !== null;
    }

    /**
     * The Agent API reports a run status rather than a per-choice finish
     * reason. An unsuccessful run never reaches here — assertRunSucceeded
     * throws first — so this maps the states that can arrive on a live read.
     *
     * A chunk with no run status falls back to the Sonar-shaped per-choice
     * finish reason (recorded or proxied responses), where Perplexity
     * documents `stop` and `length` as the only values.
     *
     * @link https://docs.perplexity.ai/api-reference/sonar-post
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractsFinishReason(array $data): FinishReason
    {
        $status = data_get($data, 'status');

        if ($status !== null) {
            return match ($status) {
                'completed' => FinishReason::Stop,
                default => FinishReason::Unknown,
            };
        }

        return match (data_get($data, 'choices.0.finish_reason')) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
