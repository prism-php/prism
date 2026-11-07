<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Mistral\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * Mistral's `prompt_tokens` includes cached input and `completion_tokens`
     * includes reasoning. The legacy `num_cached_tokens` field is not read: its
     * relationship to `prompt_tokens` is undocumented.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mapUsage(array $data): Usage
    {
        $cachedTokens = data_get($data, 'usage.prompt_tokens_details.cached_tokens');
        $reasoningTokens = data_get($data, 'usage.completion_tokens_details.reasoning_tokens');

        return new Usage(
            promptTokens: max(0, (int) data_get($data, 'usage.prompt_tokens', 0) - (int) $cachedTokens),
            completionTokens: (int) data_get($data, 'usage.completion_tokens', 0),
            cacheReadInputTokens: $cachedTokens === null ? null : (int) $cachedTokens,
            thoughtTokens: $reasoningTokens === null ? null : (int) $reasoningTokens,
        );
    }
}
