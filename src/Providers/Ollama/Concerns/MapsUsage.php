<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Ollama\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * Ollama's `prompt_eval_count` is the full input, cached tokens included.
     * Servers from 0.33.3 report the cached part as `prompt_eval_cached_count`;
     * older servers omit it, which leaves the cache bucket null.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapUsage(array $data): Usage
    {
        $inputTokens = max(0, (int) data_get($data, 'prompt_eval_count', 0));
        $cachedTokens = data_get($data, 'prompt_eval_cached_count');
        $cachedTokens = $cachedTokens === null ? null : min(max(0, (int) $cachedTokens), $inputTokens);

        return new Usage(
            promptTokens: $inputTokens - (int) $cachedTokens,
            completionTokens: (int) data_get($data, 'eval_count', 0),
            cacheReadInputTokens: $cachedTokens,
        );
    }
}
