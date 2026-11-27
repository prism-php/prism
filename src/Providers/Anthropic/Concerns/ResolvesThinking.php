<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Prism\Prism\Contracts\PrismRequest;

trait ResolvesThinking
{
    /**
     * The `thinking` field Anthropic is sent.
     *
     * `thinking.type` set: the thinking array is sent as given (API field
     * names), with Prism's `enabled` flag removed and `budgetTokens`
     * translated for `type: enabled`. Otherwise the legacy
     * `thinking.enabled` flag maps to an `enabled` payload with a budget.
     *
     * `withReasoning(false)`, `false`, an empty map, and a map with `enabled`
     * and no `type` that is not `true` all ask for no thinking. Any other value
     * is sent as given rather than dropped: a caller who asked for thinking in
     * a shape Prism does not spell itself used to silently get none. The
     * TypeScript and Python ports apply the same rules.
     */
    protected static function resolveThinking(PrismRequest $request): mixed
    {
        if (method_exists($request, 'reasoningEnabled') && $request->reasoningEnabled() === false) {
            return null;
        }

        $thinking = $request->providerOptions('thinking');

        // `false` asks for no thinking. Sending it would turn a config such as
        // env('THINKING', false) into a 400 on every request.
        if ($thinking === false) {
            return null;
        }

        if (! is_array($thinking)) {
            return $thinking;
        }

        if ($thinking === []) {
            return null;
        }

        if (is_string($thinking['type'] ?? null)) {
            return static::passthroughThinking($thinking);
        }

        if (($thinking['enabled'] ?? null) === true) {
            return [
                'type' => 'enabled',
                'budget_tokens' => static::thinkingBudget($thinking['budgetTokens'] ?? null),
            ];
        }

        if (array_key_exists('enabled', $thinking)) {
            return null;
        }

        return $thinking;
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

    /**
     * Cast: ANTHROPIC_DEFAULT_THINKING_BUDGET arrives from the environment as
     * a string, and budget_tokens is an integer. The key is the one
     * config/prism.php defines.
     */
    protected static function thinkingBudget(mixed $budgetTokens): int
    {
        return is_int($budgetTokens)
            ? $budgetTokens
            : (int) config('prism.providers.anthropic.default_thinking_budget', 1024);
    }

    /**
     * Whether the request turns thinking on, which rules out forcing a tool.
     */
    protected static function thinkingIsOn(PrismRequest $request): bool
    {
        $thinking = static::resolveThinking($request);

        return is_array($thinking) && ($thinking['type'] ?? null) !== 'disabled';
    }
}
