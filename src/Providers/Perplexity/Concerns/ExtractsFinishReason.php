<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\Enums\FinishReason;

trait ExtractsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function hasFinishReason(array $data): bool
    {
        return data_get($data, 'choices.0.finish_reason') !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractsFinishReason(array $data): FinishReason
    {
        $rawFinishReason = data_get($data, 'choices.0.finish_reason');

        return match ($rawFinishReason) {
            'stop' => FinishReason::Stop,
            default => FinishReason::Unknown,
        };
    }
}
