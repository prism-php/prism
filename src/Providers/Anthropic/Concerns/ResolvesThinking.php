<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Prism\Prism\Contracts\PrismRequest;

trait ResolvesThinking
{
    /**
     * `thinking.type` set: the thinking array is sent as given (API field
     * names), with Prism's `enabled` flag removed and `budgetTokens`
     * translated for `type: enabled`. Otherwise the legacy
     * `thinking.enabled` flag maps to an `enabled` payload with a budget.
     *
     * @return array<string, mixed>|null
     */
    protected static function resolveThinking(PrismRequest $request): ?array
    {
        $thinking = $request->providerOptions('thinking');

        if (is_array($thinking) && is_string($thinking['type'] ?? null)) {
            return static::passthroughThinking($thinking);
        }

        if ($request->providerOptions('thinking.enabled') === true) {
            return [
                'type' => 'enabled',
                'budget_tokens' => static::thinkingBudget($thinking['budgetTokens'] ?? null),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $thinking
     * @return array<string, mixed>
     */
    protected static function passthroughThinking(array $thinking): array
    {
        $budgetTokens = $thinking['budgetTokens'] ?? null;

        unset($thinking['enabled'], $thinking['budgetTokens']);

        if ($thinking['type'] === 'enabled' && ! isset($thinking['budget_tokens'])) {
            $thinking['budget_tokens'] = static::thinkingBudget($budgetTokens);
        }

        return $thinking;
    }

    protected static function thinkingBudget(mixed $budgetTokens): int
    {
        return is_int($budgetTokens)
            ? $budgetTokens
            : (int) config('prism.anthropic.default_thinking_budget', 1024);
    }
}
