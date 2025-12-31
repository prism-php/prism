<?php

namespace Prism\Prism\Providers\Perplexity\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait ExtractsUsage
{
    protected function extractUsage(array $data): Usage
    {
        return new Usage(
            promptTokens: data_get($data, 'usage.prompt_tokens'),
            completionTokens: data_get($data, 'usage.completion_tokens'),
        );
    }
}
