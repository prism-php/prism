<?php

declare(strict_types=1);

namespace Prism\Prism\Streaming;

use Prism\Prism\Providers\OpenRouter\ValueObjects\Billing;
use Prism\Prism\ValueObjects\StreamFailureUsage;
use Prism\Prism\ValueObjects\Usage;

/** Numeric reports for one provider request, independent of completed-step totals. */
class StreamFailureUsageTracker
{
    /** @var array<string, int|float> */
    private array $reported = [];

    /** @var array<string, mixed> */
    private array $billing = [];

    public function __construct(private readonly string $provider, private readonly int $stepIndex = 0) {}

    /** @param array<string, mixed> $data */
    public function record(array $data): void
    {
        $raw = match ($this->provider) {
            'anthropic' => data_get($data, 'message.usage', $data['usage'] ?? null),
            'openai' => data_get($data, 'response.usage', $data['usage'] ?? null),
            'gemini', 'vertex' => $data['usageMetadata'] ?? null,
            'ollama' => $data,
            'replicate' => $data['metrics'] ?? null,
            'perplexity' => data_get($data, 'response.usage', $data['usage'] ?? null),
            'groq' => data_get($data, 'x_groq.usage', $data['usage'] ?? null),
            default => $data['usage'] ?? null,
        };
        if (! is_array($raw)) {
            return;
        }

        if ($this->provider === 'anthropic' && is_int($raw['cache_creation_input_tokens'] ?? null) && isset($this->reported['cache_write_input_tokens']) && $raw['cache_creation_input_tokens'] !== $this->reported['cache_write_input_tokens']) {
            unset($this->reported['cache_write_5m_input_tokens'], $this->reported['cache_write_1h_input_tokens']);
        }

        $paths = match ($this->provider) {
            'anthropic' => [
                'prompt_tokens' => 'input_tokens', 'completion_tokens' => 'output_tokens',
                'cache_write_input_tokens' => 'cache_creation_input_tokens', 'cache_read_input_tokens' => 'cache_read_input_tokens',
                'thought_tokens' => 'output_tokens_details.thinking_tokens',
                'cache_write_5m_input_tokens' => 'cache_creation.ephemeral_5m_input_tokens',
                'cache_write_1h_input_tokens' => 'cache_creation.ephemeral_1h_input_tokens',
            ],
            'gemini', 'vertex' => [
                'prompt_tokens' => 'promptTokenCount', 'completion_tokens' => 'candidatesTokenCount',
                'cache_read_input_tokens' => 'cachedContentTokenCount', 'thought_tokens' => 'thoughtsTokenCount',
                'tool_input' => 'toolUsePromptTokenCount',
            ],
            'ollama' => ['prompt_tokens' => 'prompt_eval_count', 'completion_tokens' => 'eval_count'],
            'replicate' => ['prompt_tokens' => 'input_token_count', 'completion_tokens' => 'output_token_count'],
            default => [
                'prompt_tokens' => array_key_exists('input_tokens', $raw) ? 'input_tokens' : 'prompt_tokens',
                'completion_tokens' => array_key_exists('output_tokens', $raw) ? 'output_tokens' : 'completion_tokens',
                'cache_read_input_tokens' => array_key_exists('input_tokens', $raw) ? 'input_tokens_details.cached_tokens' : 'prompt_tokens_details.cached_tokens',
                'cache_write_input_tokens' => 'input_tokens_details.cache_write_tokens',
                'thought_tokens' => array_key_exists('output_tokens', $raw) ? 'output_tokens_details.reasoning_tokens' : 'completion_tokens_details.reasoning_tokens',
                'cost' => $this->provider === 'perplexity' ? 'cost.total_cost' : 'cost',
            ],
        };

        if ($this->provider === 'deepseek' && array_key_exists('prompt_cache_hit_tokens', $raw)) {
            $paths['cache_read_input_tokens'] = 'prompt_cache_hit_tokens';
        }
        if ($this->provider === 'openrouter') {
            $paths['cache_write_input_tokens'] = 'prompt_tokens_details.cache_write_tokens';
            $facts = Billing::fromUsage($raw);
            if ($facts['is_byok'] !== null) {
                $this->billing['is_byok'] = $facts['is_byok'];
            }
            if ($facts['router_cost'] !== null) {
                $this->billing['cost'] = $facts['router_cost'];
            }
            if ($facts['upstream_cost'] !== null) {
                $this->billing['cost_details'] = ['upstream_inference_cost' => $facts['upstream_cost']];
            }
            $cost = Billing::cost(Billing::fromUsage($this->billing));
            if ($cost !== null) {
                $this->reported['cost'] = $cost;
            } else {
                unset($this->reported['cost']);
            }
            unset($paths['cost']);
        } elseif ($this->provider !== 'perplexity') {
            unset($paths['cost']);
        }
        if ($this->provider === 'xai') {
            $paths['total_tokens'] = 'total_tokens';
        }
        if (in_array($this->provider, ['azure', 'z', 'qwen'], true)) {
            unset($paths['thought_tokens']);
        }

        foreach ($paths as $field => $key) {
            $value = data_get($raw, $key);
            if ($field === 'cost' ? (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 : is_int($value) && $value >= 0) {
                if ($this->provider === 'requesty' && isset($this->reported[$field])) {
                    if ($value > PHP_INT_MAX - $this->reported[$field]) {
                        unset($this->reported[$field]);

                        continue;
                    }
                    $value += $this->reported[$field];
                }
                $this->reported[$field] = $value;
            }
        }
    }

    public function snapshot(): ?StreamFailureUsage
    {
        $r = $this->reported;
        if ($r === [] || array_diff(array_keys($r), ['tool_input']) === []) {
            return null;
        }

        $prompt = (int) ($r['prompt_tokens'] ?? 0);
        $completion = (int) ($r['completion_tokens'] ?? 0);
        if (! in_array($this->provider, ['anthropic', 'ollama', 'replicate', 'perplexity'], true)) {
            $prompt = max(0, $prompt - (int) ($r['cache_read_input_tokens'] ?? 0));
        }
        if (in_array($this->provider, ['openai', 'openrouter'], true)) {
            $prompt = max(0, $prompt - (int) ($r['cache_write_input_tokens'] ?? 0));
        }
        if ($this->provider === 'gemini') {
            if (($r['tool_input'] ?? 0) > PHP_INT_MAX - $prompt || ($r['thought_tokens'] ?? 0) > PHP_INT_MAX - $completion) {
                return null;
            }
            $prompt += (int) ($r['tool_input'] ?? 0);
            $completion += (int) ($r['thought_tokens'] ?? 0);
        }
        if ($this->provider === 'xai' && ($r['total_tokens'] ?? null) !== (int) ($r['prompt_tokens'] ?? 0) + $completion) {
            if (($r['thought_tokens'] ?? 0) > PHP_INT_MAX - $completion) {
                return null;
            }
            $completion += (int) ($r['thought_tokens'] ?? 0);
        }
        $usage = new Usage(
            promptTokens: $prompt,
            completionTokens: $completion,
            cacheWriteInputTokens: isset($r['cache_write_input_tokens']) ? (int) $r['cache_write_input_tokens'] : null,
            cacheReadInputTokens: isset($r['cache_read_input_tokens']) ? (int) $r['cache_read_input_tokens'] : null,
            thoughtTokens: isset($r['thought_tokens']) ? (int) $r['thought_tokens'] : null,
            cost: isset($r['cost']) ? (float) $r['cost'] : null,
            cacheWrite5mInputTokens: isset($r['cache_write_5m_input_tokens']) ? (int) $r['cache_write_5m_input_tokens'] : null,
            cacheWrite1hInputTokens: isset($r['cache_write_1h_input_tokens']) ? (int) $r['cache_write_1h_input_tokens'] : null,
        );
        $fields = array_values(array_intersect(array_keys($usage->toArray()), array_keys($r)));

        if ($fields === []) {
            return null;
        }

        return new StreamFailureUsage($usage, $this->stepIndex, $fields);
    }
}
