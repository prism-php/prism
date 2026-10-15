<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Anthropic\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Anthropic\Maps\FinishReasonMap;
use Prism\Prism\Providers\Anthropic\Support\ContentBlocks;
use Prism\Prism\ValueObjects\ToolCall;

trait ExtractsAdditionalContent
{
    use ExtractsCitations, ExtractsThinking;

    /**
     * Replay state first (the ordered `content_blocks`), then the convenience
     * keys derived from it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractAdditionalContent(array $data): array
    {
        $data = $this->withReplaySafeContent($data);
        $contentBlocks = data_get($data, 'content');

        return Arr::whereNotNull([
            'citations' => $this->extractCitations($data),
            ...$this->extractThinking($data),
            ...$this->extractProviderToolContent($data),
            'content_blocks' => is_array($contentBlocks) ? array_values($contentBlocks) : null,
            'stop_reason' => data_get($data, 'stop_reason'),
            'stop_details' => data_get($data, 'stop_details'),
            'fallbacks' => ContentBlocks::fallbacks(is_array($contentBlocks) ? $contentBlocks : []) ?: null,
            'usage_iterations' => data_get($data, 'usage.iterations'),
        ]);
    }

    /**
     * Only a turn that ended in `tool_use` has its client tool calls executed,
     * so any other turn is stored without the tool use it left unanswered.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withReplaySafeContent(array $data): array
    {
        $finishReason = FinishReasonMap::map((string) data_get($data, 'stop_reason', ''));

        if ($finishReason === FinishReason::ToolCalls || ! is_array($data['content'] ?? null)) {
            return $data;
        }

        $data['content'] = ContentBlocks::withoutUnansweredToolUse(
            array_values($data['content']),
            keepServerToolUse: $finishReason === FinishReason::Pause,
        );

        return $data;
    }

    /**
     * Extract server tool use and result content blocks from the response.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function extractProviderToolContent(array $data): array
    {
        $providerToolCalls = [];
        $providerToolResults = [];

        foreach (data_get($data, 'content') ?? [] as $content) {
            $type = data_get($content, 'type');

            if ($type === 'server_tool_use') {
                $providerToolCalls[] = [
                    'type' => $type,
                    'id' => data_get($content, 'id'),
                    'name' => data_get($content, 'name'),
                    'input' => json_encode(data_get($content, 'input', [])),
                ];
            }

            if (str_ends_with((string) $type, '_tool_result')) {
                $providerToolResults[] = [
                    'type' => $type,
                    'tool_use_id' => data_get($content, 'tool_use_id'),
                    'content' => data_get($content, 'content'),
                ];
            }
        }

        return Arr::whereNotNull([
            'provider_tool_calls' => $providerToolCalls !== [] ? $providerToolCalls : null,
            'provider_tool_results' => $providerToolResults !== [] ? $providerToolResults : null,
        ]);
    }

    /**
     * Client tool calls the caller should execute: `tool_use` blocks produced
     * by the serving model (after the final `fallback` block, if any), in a
     * turn that ended in `tool_use`.
     *
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = [];

        foreach (ContentBlocks::servingBlocks(data_get($this->withReplaySafeContent($data), 'content') ?? []) as $content) {
            if (data_get($content, 'type') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: data_get($content, 'id'),
                    name: data_get($content, 'name'),
                    arguments: data_get($content, 'input')
                );
            }
        }

        return $toolCalls;
    }
}
