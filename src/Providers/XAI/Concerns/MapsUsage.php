<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\XAI\Concerns;

use Prism\Prism\ValueObjects\Usage;

trait MapsUsage
{
    /**
     * xAI's `prompt_tokens` includes the cached tokens, so the prompt bucket is
     * what remains once `cached_tokens` is taken out. Whether `completion_tokens`
     * already holds the reasoning is decided from the raw counters: a total of
     * prompt + completion means it does, anything else (prompt + completion +
     * reasoning, or no usable total) gets xAI's documented default, where
     * reasoning is reported next to `completion_tokens` and is added to it.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function mapUsage(array $usage): Usage
    {
        $rawPromptTokens = (int) data_get($usage, 'prompt_tokens', 0);
        $completionTokens = (int) data_get($usage, 'completion_tokens', 0);
        $cacheReadTokens = $this->reportedTokenCount($usage, 'prompt_tokens_details.cached_tokens');
        $reasoningTokens = $this->reportedTokenCount($usage, 'completion_tokens_details.reasoning_tokens');

        if (! $this->completionIncludesReasoning($usage, $rawPromptTokens, $completionTokens)) {
            $completionTokens += (int) $reasoningTokens;
        }

        return new Usage(
            promptTokens: max(0, $rawPromptTokens - (int) $cacheReadTokens),
            completionTokens: $completionTokens,
            cacheReadInputTokens: $cacheReadTokens,
            thoughtTokens: $reasoningTokens,
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    protected function completionIncludesReasoning(array $usage, int $rawPromptTokens, int $completionTokens): bool
    {
        $totalTokens = $this->reportedTokenCount($usage, 'total_tokens');

        return $totalTokens === $rawPromptTokens + $completionTokens;
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
