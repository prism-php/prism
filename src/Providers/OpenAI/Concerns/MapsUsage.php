<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapUsage(array $data): Usage
    {
        return new Usage(
            promptTokens: data_get($data, 'usage.input_tokens', 0) - data_get($data, 'usage.input_tokens_details.cached_tokens', 0),
            completionTokens: data_get($data, 'usage.output_tokens'),
            cacheWriteInputTokens: data_get($data, 'usage.input_tokens_details.cache_write_tokens'),
            cacheReadInputTokens: data_get($data, 'usage.input_tokens_details.cached_tokens'),
            thoughtTokens: data_get($data, 'usage.output_tokens_details.reasoning_tokens'),
        );
    }
}
