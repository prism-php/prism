<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Listeners;

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationCompleted;
use Prism\Prism\Telemetry\Events\EmbeddingGenerationStarted;
use Prism\Prism\Telemetry\Events\HttpCallCompleted;
use Prism\Prism\Telemetry\Events\HttpCallStarted;
use Prism\Prism\Telemetry\Events\StreamingCompleted;
use Prism\Prism\Telemetry\Events\StreamingStarted;
use Prism\Prism\Telemetry\Events\StructuredOutputCompleted;
use Prism\Prism\Telemetry\Events\StructuredOutputStarted;
use Prism\Prism\Telemetry\Events\TextGenerationCompleted;
use Prism\Prism\Telemetry\Events\TextGenerationStarted;
use Prism\Prism\Telemetry\Events\ToolCallCompleted;
use Prism\Prism\Telemetry\Events\ToolCallStarted;

class TelemetryEventListener
{
    public function __construct(
        protected TelemetryDriver $driver
    ) {}

    public function handleTextGenerationStarted(TextGenerationStarted $event): void
    {
        $this->driver->startSpan('text_generation', [
            'span_id' => $event->spanId,
            'model' => $event->request->model ?? 'unknown',
            'context' => $event->context,
        ]);
    }

    public function handleTextGenerationCompleted(TextGenerationCompleted $event): void
    {
        $this->driver->endSpan($event->spanId, [
            'usage' => [
                'prompt_tokens' => $event->response->usage->promptTokens,
                'completion_tokens' => $event->response->usage->completionTokens,
            ],
            'finish_reason' => $event->response->finishReason->name,
            'context' => $event->context,
        ]);
    }

    public function handleStructuredOutputStarted(StructuredOutputStarted $event): void
    {
        $this->driver->startSpan('structured_output', [
            'span_id' => $event->spanId,
            'model' => $event->request->model ?? 'unknown',
            'schema' => $event->request->schema()->name(),
            'context' => $event->context,
        ]);
    }

    public function handleStructuredOutputCompleted(StructuredOutputCompleted $event): void
    {
        $this->driver->endSpan($event->spanId, [
            'usage' => [
                'prompt_tokens' => $event->response->usage->promptTokens,
                'completion_tokens' => $event->response->usage->completionTokens,
            ],
            'finish_reason' => $event->response->finishReason->name,
            'context' => $event->context,
        ]);
    }

    public function handleEmbeddingGenerationStarted(EmbeddingGenerationStarted $event): void
    {
        $this->driver->startSpan('embedding_generation', [
            'span_id' => $event->spanId,
            'model' => $event->request->model ?? 'unknown',
            'input_count' => count($event->request->inputs()),
            'context' => $event->context,
        ]);
    }

    public function handleEmbeddingGenerationCompleted(EmbeddingGenerationCompleted $event): void
    {
        $this->driver->endSpan($event->spanId, [
            'usage' => [
                'prompt_tokens' => $event->response->usage->tokens,
            ],
            'embedding_count' => count($event->response->embeddings),
            'context' => $event->context,
        ]);
    }

    public function handleStreamingStarted(StreamingStarted $event): void
    {
        $this->driver->startSpan('streaming', [
            'span_id' => $event->spanId,
            'model' => $event->request->model ?? 'unknown',
            'context' => $event->context,
        ]);
    }

    public function handleStreamingCompleted(StreamingCompleted $event): void
    {
        $this->driver->endSpan($event->spanId, [
            'context' => $event->context,
        ]);
    }

    public function handleHttpCallStarted(HttpCallStarted $event): void
    {
        $this->driver->startSpan('http_call', [
            'span_id' => $event->spanId,
            'method' => $event->method,
            'url' => $event->url,
            'context' => $event->context,
        ]);
    }

    public function handleHttpCallCompleted(HttpCallCompleted $event): void
    {
        $this->driver->endSpan($event->spanId, [
            'status_code' => $event->statusCode,
            'context' => $event->context,
        ]);
    }

    public function handleToolCallStarted(ToolCallStarted $event): void
    {
        $this->driver->startSpan('tool_call', [
            'span_id' => $event->spanId,
            'tool_name' => $event->toolCall->name,
            'context' => $event->context,
        ]);
    }

    public function handleToolCallCompleted(ToolCallCompleted $event): void
    {
        $this->driver->endSpan($event->spanId, [
            'tool_name' => $event->toolCall->name,
            'context' => $event->context,
        ]);
    }
}
