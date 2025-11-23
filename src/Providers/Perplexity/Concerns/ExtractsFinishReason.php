<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\Enums\FinishReason;

trait ExtractsFinishReason
{
    protected function extractsFinishReason(array $data): FinishReason
    {
        $rawFinishReason = data_get($data, 'choices.0.finish_reason');

        return match ($rawFinishReason) {
            'stop' => FinishReason::Stop,
            default => FinishReason::Unknown,
        };
    }
}
