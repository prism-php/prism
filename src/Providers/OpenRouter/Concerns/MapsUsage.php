<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenRouter\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * OpenRouter's `prompt_tokens` includes the tokens read from the cache
     * (`cached_tokens`) and the tokens written to it (`cache_write_tokens`),
     * so the prompt bucket is what remains once both are taken out. Replayed
     * response-cache hits can report more cached tokens than prompt tokens,
     * hence the floor at zero. `completion_tokens` already includes reasoning.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        $cacheReadTokens = $this->reportedTokenCount($usage, 'prompt_tokens_details.cached_tokens');
        $cacheWriteTokens = $this->reportedTokenCount($usage, 'prompt_tokens_details.cache_write_tokens');
        $promptTokens = (int) data_get($usage, 'prompt_tokens', 0);

        return new Usage(
            promptTokens: max(0, $promptTokens - (int) $cacheReadTokens - (int) $cacheWriteTokens),
            completionTokens: (int) data_get($usage, 'completion_tokens', 0),
            cacheWriteInputTokens: $cacheWriteTokens,
            cacheReadInputTokens: $cacheReadTokens,
            thoughtTokens: $this->reportedTokenCount($usage, 'completion_tokens_details.reasoning_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    protected function reportedTokenCount(array $usage, string $key): ?int
    {
        $tokens = data_get($usage, $key);

        return $tokens === null ? null : (int) $tokens;
    }
}
