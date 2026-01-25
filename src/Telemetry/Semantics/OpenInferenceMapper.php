<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Semantics;

use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;

/**
 * Maps Prism span attributes to OpenInference semantic conventions.
 *
 * OpenInference is the semantic convention used by Phoenix/Arize for AI observability.
 *
 * @see https://github.com/Arize-ai/openinference/blob/main/spec/semantic_conventions.md
 */
class OpenInferenceMapper implements SemanticMapperInterface
{
    /**
     * Convert generic Prism attributes to OpenInference format.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function map(string $operation, array $attributes): array
    {
        $attrs = match ($operation) {
            'text_generation', 'streaming' => $this->buildLlmSpan($attributes),
            'tool_call' => $this->buildToolSpan($attributes),
            'embedding_generation' => $this->buildEmbeddingSpan($attributes),
            'structured_output' => $this->buildChainSpan($attributes),
            'http_call' => $this->buildHttpSpan($attributes),
            default => $this->buildChainSpan($attributes),
        };

        return $this->filterNulls($attrs);
    }

    /**
     * Convert events to OpenInference format.
     *
     * @param  array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>  $events
     * @return array<int, array{name: string, timeNanos: int, attributes: array<string, mixed>}>
     */
    public function mapEvents(array $events): array
    {
        return array_map(fn (array $e): array => [
            'name' => $e['name'],
            'timeNanos' => $e['timeNanos'],
            'attributes' => $e['name'] === 'exception' ? [
                'exception.type' => $e['attributes']['type'] ?? 'Unknown',
                'exception.message' => $e['attributes']['message'] ?? '',
                'exception.stacktrace' => $e['attributes']['stacktrace'] ?? '',
                'exception.escaped' => true,
            ] : $e['attributes'],
        ], $events);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function buildLlmSpan(array $a): array
    {
        $attrs = [
            'openinference.span.kind' => 'LLM',
            'llm.model_name' => $a['model'] ?? null,
            'llm.response.model' => $a['response_model'] ?? null,
            'llm.provider' => $a['provider'] ?? null,
            'llm.service_tier' => $a['service_tier'] ?? null,
            'llm.invocation_parameters' => $this->buildInvocationParams($a),
            'llm.response.id' => $a['response_id'] ?? null,
            'llm.tool_choice' => isset($a['tool_choice']) ? json_encode($a['tool_choice']) : null,
            // Prompt template support (if provided)
            'llm.prompt_template.template' => $a['prompt_template'] ?? null,
            'llm.prompt_template.variables' => isset($a['prompt_variables']) ? json_encode($a['prompt_variables']) : null,
        ];

        $this->addSystemPrompt($attrs, $a['messages'] ?? []);
        $this->addInputOutput($attrs, $a);
        $this->addTokenUsage($attrs, $a['usage'] ?? null);
        $this->addInputMessages($attrs, $a['messages'] ?? []);
        $this->addOutputMessages($attrs, $a['output'] ?? '', $a['tool_calls'] ?? [], $a['finish_reason'] ?? null);
        $this->addToolDefinitions($attrs, $a['tools'] ?? []);
        $this->addMetadata($attrs, $a['metadata'] ?? []);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function buildToolSpan(array $a): array
    {
        $tool = $a['tool'] ?? [];
        $output = $a['output'] ?? null;

        $attrs = [
            'openinference.span.kind' => 'TOOL',
            'tool.name' => $tool['name'] ?? null,
            'tool.call.id' => $tool['call_id'] ?? null,
            'tool.call.function.name' => $tool['name'] ?? null,
            'tool.call.function.arguments' => isset($tool['arguments']) ? json_encode($tool['arguments']) : null,
            'tool.description' => $tool['description'] ?? null,
            'tool.parameters' => isset($tool['parameters']) ? json_encode($tool['parameters']) : null,
            'tool.output' => is_string($output) ? $output : (isset($output) ? json_encode($output) : null),
            'input.value' => isset($a['input']) ? json_encode($a['input']) : null,
            'input.mime_type' => isset($a['input']) ? 'application/json' : null,
            'output.value' => is_string($output) ? $output : (isset($output) ? json_encode($output) : null),
            'output.mime_type' => isset($output) ? (is_string($output) ? 'text/plain' : 'application/json') : null,
        ];

        $this->addMetadata($attrs, $a['metadata'] ?? []);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function buildEmbeddingSpan(array $a): array
    {
        $attrs = [
            'openinference.span.kind' => 'EMBEDDING',
            'embedding.model_name' => $a['model'] ?? null,
            'embedding.provider' => $a['provider'] ?? null,
        ];

        // Per OpenInference spec: embedding.embeddings.{i}.embedding.text
        foreach ($a['inputs'] ?? [] as $i => $text) {
            $attrs["embedding.embeddings.{$i}.embedding.text"] = $text;
        }

        // Also set input.value as per spec for span-level input
        if (! empty($a['inputs'])) {
            $attrs['input.value'] = count($a['inputs']) === 1
                ? $a['inputs'][0]
                : json_encode($a['inputs']);
            $attrs['input.mime_type'] = count($a['inputs']) === 1
                ? 'text/plain'
                : 'application/json';
        }

        $this->addTokenUsage($attrs, $a['usage'] ?? null);
        $this->addMetadata($attrs, $a['metadata'] ?? []);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function buildChainSpan(array $a): array
    {
        $attrs = [
            'openinference.span.kind' => 'CHAIN',
            'llm.model_name' => $a['model'] ?? null,
            'llm.response.model' => $a['response_model'] ?? null,
            'llm.provider' => $a['provider'] ?? null,
            'llm.service_tier' => $a['service_tier'] ?? null,
            'llm.response.id' => $a['response_id'] ?? null,
            'output.schema.name' => $a['schema']['name'] ?? null,
            'output.schema' => isset($a['schema']['definition']) ? json_encode($a['schema']['definition']) : null,
        ];

        $this->addInputOutput($attrs, $a);
        $this->addTokenUsage($attrs, $a['usage'] ?? null);
        $this->addInputMessages($attrs, $a['messages'] ?? []);
        $this->addToolDefinitions($attrs, $a['tools'] ?? []);
        $this->addMetadata($attrs, $a['metadata'] ?? []);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function buildHttpSpan(array $a): array
    {
        $http = $a['http'] ?? [];

        $attrs = [
            'openinference.span.kind' => 'CHAIN',
            'http.method' => $http['method'] ?? null,
            'http.url' => $http['url'] ?? null,
            'http.status_code' => $http['status_code'] ?? null,
        ];

        $this->addMetadata($attrs, $a['metadata'] ?? []);

        return $attrs;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    protected function buildInvocationParams(array $a): ?string
    {
        $params = $this->filterNulls([
            'temperature' => $a['temperature'] ?? null,
            'max_tokens' => $a['max_tokens'] ?? null,
            'top_p' => $a['top_p'] ?? null,
        ]);

        return $params !== [] ? (json_encode($params) ?: null) : null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function addSystemPrompt(array &$attrs, array $messages): void
    {
        $systemContent = collect($messages)
            ->filter(fn ($m): bool => ($m['role'] ?? '') === 'system')
            ->pluck('content')
            ->implode("\n");

        if ($systemContent) {
            $attrs['llm.system'] = $systemContent;
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $a
     */
    protected function addInputOutput(array &$attrs, array $a): void
    {
        $input = $a['input'] ?? null;
        $output = $a['output'] ?? null;

        $attrs['input.value'] = is_string($input) ? $input : (isset($input) ? json_encode($input) : null);
        $attrs['input.mime_type'] = isset($input) ? (is_string($input) ? 'text/plain' : 'application/json') : null;
        $attrs['output.value'] = is_string($output) ? $output : (isset($output) ? json_encode($output) : null);
        $attrs['output.mime_type'] = isset($output) ? (is_string($output) ? 'text/plain' : 'application/json') : null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>|null  $usage
     */
    protected function addTokenUsage(array &$attrs, ?array $usage): void
    {
        if (! $usage) {
            return;
        }

        $prompt = $usage['prompt_tokens'] ?? $usage['tokens'] ?? null;
        $completion = $usage['completion_tokens'] ?? null;

        $attrs['llm.token_count.prompt'] = $prompt;
        $attrs['llm.token_count.completion'] = $completion;
        $attrs['llm.token_count.total'] = ($prompt !== null && $completion !== null) ? $prompt + $completion : null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $messages
     */
    protected function addInputMessages(array &$attrs, array $messages): void
    {
        foreach ($messages as $i => $msg) {
            $attrs["llm.input_messages.{$i}.message.role"] = $msg['role'];
            $attrs["llm.input_messages.{$i}.message.content"] = $msg['content'];

            foreach ($msg['tool_calls'] ?? [] as $j => $tc) {
                $prefix = "llm.input_messages.{$i}.message.tool_calls.{$j}.tool_call";
                $attrs["{$prefix}.id"] = $tc['id'] ?? null;
                $attrs["{$prefix}.function.name"] = $tc['name'];
                $attrs["{$prefix}.function.arguments"] = json_encode($tc['arguments']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    protected function addOutputMessages(array &$attrs, string $text, array $toolCalls, ?string $finishReason = null): void
    {
        if (! $text && ! $toolCalls) {
            return;
        }

        $attrs['llm.output_messages.0.message.role'] = 'assistant';
        $attrs['llm.output_messages.0.message.content'] = $text;

        // Per OpenInference spec, finish_reason goes on the output message
        if ($finishReason !== null) {
            $attrs['llm.output_messages.0.message.finish_reason'] = $finishReason;
        }

        foreach ($toolCalls as $i => $tc) {
            $prefix = "llm.output_messages.0.message.tool_calls.{$i}.tool_call";
            $attrs["{$prefix}.id"] = $tc['id'];
            $attrs["{$prefix}.function.name"] = $tc['name'];
            $attrs["{$prefix}.function.arguments"] = json_encode($tc['arguments']);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $tools
     */
    protected function addToolDefinitions(array &$attrs, array $tools): void
    {
        foreach ($tools as $i => $tool) {
            $attrs["llm.tools.{$i}.tool.json_schema"] = json_encode([
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ]);
        }
    }

    /**
     * Add metadata to attributes, handling special OpenInference user/session attributes.
     *
     * @see https://github.com/Arize-ai/openinference/blob/main/spec/semantic_conventions.md#user-attributes
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $metadata
     */
    protected function addMetadata(array &$attrs, array $metadata): void
    {
        // Handle special OpenInference user attributes
        if (isset($metadata['user_id'])) {
            $attrs['user.id'] = (string) $metadata['user_id'];
            unset($metadata['user_id']);
        }

        if (isset($metadata['session_id'])) {
            $attrs['session.id'] = (string) $metadata['session_id'];
            unset($metadata['session_id']);
        }

        // Handle agent as a reserved OpenInference attribute
        // @see https://arize-ai.github.io/openinference/spec/semantic_conventions.html
        if (isset($metadata['agent'])) {
            $attrs['agent.name'] = (string) $metadata['agent'];
            unset($metadata['agent']);
        }

        // Handle tags per OpenInference spec - tag.tags is a list of strings
        // @see https://arize-ai.github.io/openinference/spec/semantic_conventions.html
        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            $tagList = [];
            foreach ($metadata['tags'] as $tagKey => $tagValue) {
                // Convert key-value pairs to "key:value" format
                $tagList[] = is_int($tagKey)
                    ? (string) $tagValue
                    : "{$tagKey}:{$tagValue}";
            }
            $attrs['tag.tags'] = json_encode($tagList);
            unset($metadata['tags']);
        }

        // Remaining metadata goes under metadata.* prefix
        foreach ($metadata as $key => $value) {
            $attrs["metadata.{$key}"] = is_string($value) ? $value : json_encode($value);
        }
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    protected function filterNulls(array $array): array
    {
        return array_filter($array, fn ($v): bool => $v !== null);
    }
}
