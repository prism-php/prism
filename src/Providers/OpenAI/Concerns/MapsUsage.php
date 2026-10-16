<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * OpenAI's `input_tokens` includes cache reads and cache writes, so the
     * prompt bucket is what remains once both are taken out.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapUsage(array $data): Usage
    {
        $cacheReadTokens = data_get($data, 'usage.input_tokens_details.cached_tokens');
        $cacheWriteTokens = data_get($data, 'usage.input_tokens_details.cache_write_tokens');
        $inputTokens = (int) data_get($data, 'usage.input_tokens', 0);

        return new Usage(
            promptTokens: max(0, $inputTokens - (int) $cacheReadTokens - (int) $cacheWriteTokens),
            completionTokens: (int) data_get($data, 'usage.output_tokens', 0),
            cacheWriteInputTokens: $cacheWriteTokens === null ? null : (int) $cacheWriteTokens,
            cacheReadInputTokens: $cacheReadTokens === null ? null : (int) $cacheReadTokens,
            thoughtTokens: data_get($data, 'usage.output_tokens_details.reasoning_tokens'),
        );
    }
}
