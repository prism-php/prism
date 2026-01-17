<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Support\Facades\Context;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Enums\ToolChoice;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\Telemetry\Events\HttpCallCompleted;
use Prism\Prism\Telemetry\Events\HttpCallStarted;
use Prism\Prism\Telemetry\Events\SpanException;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Manages span lifecycle and extracts driver-agnostic attributes from Prism events.
 */
class SpanCollector
{
    /** @var array<string, array<string, mixed>> */
    protected array $pendingSpans = [];

    public function __construct(
        protected TelemetryDriver $driver
    ) {}

    public function startSpan(TextGenerationStarted|StructuredOutputStarted|EmbeddingGenerationStarted|StreamingStarted|HttpCallStarted|ToolCallStarted $event): string
    {
        $spanId = $event->spanId;

        $this->pendingSpans[$spanId] = [
            'spanId' => $spanId,
            'traceId' => $event->traceId,
            'parentSpanId' => $event->parentSpanId,
            'operation' => $this->getOperation($event),
            'startTimeNano' => $event->timeNanos,
            'attributes' => $this->extractStartAttributes($event),
            'events' => [],
            'exception' => null,
        ];

        return $spanId;
    }

    public function endSpan(TextGenerationCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted|StreamingCompleted|HttpCallCompleted|ToolCallCompleted $event): void
    {
        if (! isset($this->pendingSpans[$event->spanId])) {
            return;
        }

        $pending = &$this->pendingSpans[$event->spanId];
        $endAttrs = $this->extractEndAttributes($event);

        // Deep merge metadata, shallow merge everything else
        $startMetadata = $pending['attributes']['metadata'] ?? [];
        $endMetadata = $endAttrs['metadata'] ?? [];
        unset($pending['attributes']['metadata'], $endAttrs['metadata']);

        $pending['attributes'] = array_merge($pending['attributes'], $endAttrs);

        if ($startMetadata || $endMetadata) {
            $pending['attributes']['metadata'] = array_merge($startMetadata, $endMetadata);
        }

        $this->driver->recordSpan(new SpanData(
            spanId: $pending['spanId'],
            traceId: $pending['traceId'],
            parentSpanId: $pending['parentSpanId'],
            operation: $pending['operation'],
            startTimeNano: $pending['startTimeNano'],
            endTimeNano: $event->timeNanos,
            attributes: $this->filterNulls($pending['attributes']),
            events: $pending['events'],
            exception: $pending['exception'],
        ));

        unset($this->pendingSpans[$event->spanId]);
    }

    /**
     * Shutdown the telemetry driver, flushing any buffered spans.
     *
     * Should be called when a Prism operation completes.
     */
    public function shutdown(): void
    {
        $this->driver->shutdown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $spanId, string $name, int $timeNanos, array $attributes = []): void
    {
        if (isset($this->pendingSpans[$spanId])) {
            $this->pendingSpans[$spanId]['events'][] = [
                'name' => $name,
                'timeNanos' => $timeNanos,
                'attributes' => $attributes,
            ];
        }
    }

    public function recordException(SpanException $event): void
    {
        if (isset($this->pendingSpans[$event->spanId])) {
            $this->pendingSpans[$event->spanId]['exception'] = $event->exception;
            $this->pendingSpans[$event->spanId]['events'][] = [
                'name' => 'exception',
                'timeNanos' => $event->timeNanos,
                'attributes' => [
                    'type' => $event->exception::class,
                    'message' => $event->exception->getMessage(),
                    'stacktrace' => $event->exception->getTraceAsString(),
                ],
            ];
        }
    }

    // ========================================================================
    // Operation & Attribute Extraction
    // ========================================================================

    protected function getOperation(TextGenerationStarted|StructuredOutputStarted|EmbeddingGenerationStarted|StreamingStarted|HttpCallStarted|ToolCallStarted $event): string
    {
        return match (true) {
            $event instanceof TextGenerationStarted => 'text_generation',
            $event instanceof StructuredOutputStarted => 'structured_output',
            $event instanceof EmbeddingGenerationStarted => 'embedding_generation',
            $event instanceof StreamingStarted => 'streaming',
            $event instanceof HttpCallStarted => 'http_call',
            $event instanceof ToolCallStarted => 'tool_call',
        };
    }

    /** @return array<string, mixed> */
    protected function extractStartAttributes(TextGenerationStarted|StructuredOutputStarted|EmbeddingGenerationStarted|StreamingStarted|HttpCallStarted|ToolCallStarted $event): array
    {
        $attrs = match (true) {
            $event instanceof TextGenerationStarted => $this->extractTextGenerationStart($event),
            $event instanceof StructuredOutputStarted => $this->extractStructuredOutputStart($event),
            $event instanceof EmbeddingGenerationStarted => $this->extractEmbeddingStart($event),
            $event instanceof StreamingStarted => $this->extractStreamingStart($event),
            $event instanceof HttpCallStarted => $this->extractHttpStart($event),
            $event instanceof ToolCallStarted => $this->extractToolCallStart($event),
        };

        // Add user metadata from Laravel hidden Context (set by HasTelemetryContext trait)
        $metadata = Context::getHidden('prism.telemetry.metadata');
        if (! empty($metadata)) {
            $attrs['metadata'] = $metadata;
        }

        return $attrs;
    }

    /** @return array<string, mixed> */
    protected function extractEndAttributes(TextGenerationCompleted|StructuredOutputCompleted|EmbeddingGenerationCompleted|StreamingCompleted|HttpCallCompleted|ToolCallCompleted $event): array
    {
        return match (true) {
            $event instanceof TextGenerationCompleted => $this->extractTextGenerationEnd($event),
            $event instanceof StructuredOutputCompleted => $this->extractStructuredOutputEnd($event),
            $event instanceof EmbeddingGenerationCompleted => $this->extractEmbeddingEnd($event),
            $event instanceof StreamingCompleted => $this->extractStreamingEnd($event),
            $event instanceof HttpCallCompleted => ['http' => ['status_code' => $event->statusCode]],
            $event instanceof ToolCallCompleted => $this->extractToolCallEnd($event),
        };
    }

    // ========================================================================
    // Start Event Extractors
    // ========================================================================

    /** @return array<string, mixed> */
    protected function extractTextGenerationStart(TextGenerationStarted $event): array
    {
        $r = $event->request;

        return array_merge($this->extractLlmRequestAttributes($r), [
            'tools' => $r->tools() ? $this->buildToolDefinitions($r->tools()) : null,
            'tool_choice' => $r->toolChoice() ? $this->formatToolChoice($r->toolChoice()) : null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function extractStreamingStart(StreamingStarted $event): array
    {
        $attrs = $this->extractLlmRequestAttributes($event->request);

        // Merge stream start data if available, capturing response model separately
        if ($event->streamStart !== null) {
            $startData = $event->streamStart->toArray();

            // Capture response model separately (the actual model used may differ from requested)
            if (isset($startData['model'])) {
                $attrs['response_model'] = $startData['model'];
                unset($startData['model']);
            }

            $attrs = array_merge($attrs, $startData);
        }

        return $attrs;
    }

    /** @return array<string, mixed> */
    protected function extractStructuredOutputStart(StructuredOutputStarted $event): array
    {
        $r = $event->request;

        return array_merge($this->extractLlmRequestAttributes($r), [
            'schema' => [
                'name' => $r->schema()->name(),
                'definition' => $r->schema()->toArray(),
            ],
            'tools' => $r->tools() ? $this->buildToolDefinitions($r->tools()) : null,
            'tool_choice' => $r->toolChoice() ? $this->formatToolChoice($r->toolChoice()) : null,
        ]);
    }

    /** @return array<string, mixed> */
    protected function extractEmbeddingStart(EmbeddingGenerationStarted $event): array
    {
        $r = $event->request;

        return [
            'model' => $r->model(),
            'provider' => $r->provider(),
            'inputs' => $r->inputs(),
        ];
    }

    /** @return array<string, mixed> */
    protected function extractToolCallStart(ToolCallStarted $event): array
    {
        $tc = $event->toolCall;

        return [
            'tool' => [
                'name' => $tc->name,
                'call_id' => $tc->id,
                'arguments' => $tc->arguments(),
            ],
            'input' => $tc->arguments(),
        ];
    }

    /** @return array<string, mixed> */
    protected function extractHttpStart(HttpCallStarted $event): array
    {
        return [
            'http' => [
                'method' => $event->method,
                'url' => $event->url,
            ],
        ];
    }

    // ========================================================================
    // End Event Extractors
    // ========================================================================

    /** @return array<string, mixed> */
    protected function extractTextGenerationEnd(TextGenerationCompleted $event): array
    {
        $r = $event->response;

        return [
            'output' => $r->text,
            'response_model' => $r->meta->model,
            'service_tier' => $r->meta->serviceTier,
            'usage' => [
                'prompt_tokens' => $r->usage->promptTokens,
                'completion_tokens' => $r->usage->completionTokens,
            ],
            'response_id' => $r->meta->id,
            'finish_reason' => $r->finishReason->name,
            'tool_calls' => $r->toolCalls ? array_map(fn (\Prism\Prism\ValueObjects\ToolCall $tc): array => [
                'id' => $tc->id,
                'name' => $tc->name,
                'arguments' => $tc->arguments(),
            ], $r->toolCalls) : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function extractStructuredOutputEnd(StructuredOutputCompleted $event): array
    {
        $r = $event->response;

        return [
            'output' => $r->structured,
            'response_model' => $r->meta->model,
            'service_tier' => $r->meta->serviceTier,
            'usage' => [
                'prompt_tokens' => $r->usage->promptTokens,
                'completion_tokens' => $r->usage->completionTokens,
            ],
            'response_id' => $r->meta->id,
            'finish_reason' => $r->finishReason->name,
        ];
    }

    /** @return array<string, mixed> */
    protected function extractEmbeddingEnd(EmbeddingGenerationCompleted $event): array
    {
        return [
            'usage' => ['tokens' => $event->response->usage->tokens],
            'embedding_count' => count($event->response->embeddings),
        ];
    }

    /** @return array<string, mixed> */
    protected function extractStreamingEnd(StreamingCompleted $event): array
    {
        if ($event->streamEnd === null) {
            return [];
        }

        return $event->streamEnd->toArray();
    }

    /** @return array<string, mixed> */
    protected function extractToolCallEnd(ToolCallCompleted $event): array
    {
        return [
            'output' => $event->toolResult->result,
        ];
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Extract common attributes from LLM request objects.
     *
     * @return array<string, mixed>
     */
    protected function extractLlmRequestAttributes(TextRequest|StructuredRequest $request): array
    {
        return [
            'model' => $request->model(),
            'provider' => $request->provider(),
            'temperature' => $request->temperature(),
            'max_tokens' => $request->maxTokens(),
            'top_p' => $request->topP(),
            'input' => $request->prompt(),
            'messages' => $this->buildMessages($request->systemPrompts(), $request->messages()),
        ];
    }

    /**
     * @param  SystemMessage[]  $systemPrompts
     * @param  Message[]  $messages
     * @return array<int, array{role: string, content: string, tool_calls?: array<int, array{id: string, name: string, arguments: array<string, mixed>}>}>
     */
    protected function buildMessages(array $systemPrompts, array $messages): array
    {
        $result = [];

        foreach ($systemPrompts as $prompt) {
            $result[] = ['role' => 'system', 'content' => $prompt->content];
        }

        foreach ($messages as $message) {
            if ($formatted = $this->formatMessage($message)) {
                $result[] = $formatted;
            }
        }

        return $result;
    }

    /** @return array{role: string, content: string, tool_calls?: array<int, array{id: string, name: string, arguments: array<string, mixed>}>}|null */
    protected function formatMessage(Message $message): ?array
    {
        return match (true) {
            $message instanceof UserMessage => ['role' => 'user', 'content' => $message->text()],
            $message instanceof AssistantMessage => array_filter([
                'role' => 'assistant',
                'content' => $message->content,
                'tool_calls' => $message->toolCalls ? array_map(fn (\Prism\Prism\ValueObjects\ToolCall $tc): array => [
                    'id' => $tc->id,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments(),
                ], $message->toolCalls) : null,
            ], fn ($v): bool => $v !== null),
            $message instanceof ToolResultMessage => [
                'role' => 'tool',
                'content' => implode("\n", array_map(
                    fn (\Prism\Prism\ValueObjects\ToolResult $tr): string|false => is_string($tr->result) ? $tr->result : json_encode($tr->result),
                    $message->toolResults
                )),
            ],
            $message instanceof SystemMessage => ['role' => 'system', 'content' => $message->content],
            default => null,
        };
    }

    /**
     * @param  Tool[]  $tools
     * @return array<int, array{name: string, description: string, parameters: array{type: string, properties: array<string, mixed>, required: array<int, string>}}>
     */
    protected function buildToolDefinitions(array $tools): array
    {
        return array_map(fn (Tool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => [
                'type' => 'object',
                'properties' => $tool->parametersAsArray(),
                'required' => $tool->requiredParameters(),
            ],
        ], $tools);
    }

    /** @return array{type: string, tool_name?: string} */
    protected function formatToolChoice(string|ToolChoice $toolChoice): array
    {
        return is_string($toolChoice)
            ? ['type' => 'tool', 'tool_name' => $toolChoice]
            : ['type' => strtolower($toolChoice->name)];
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
