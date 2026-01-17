<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Listeners;

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
use Prism\Prism\Telemetry\SpanCollector;

class TelemetryEventListener
{
    public function __construct(
        protected SpanCollector $collector
    ) {}

    public function handleTextGenerationStarted(TextGenerationStarted $event): void
    {
        $this->collector->startSpan($event);
    }

    public function handleTextGenerationCompleted(TextGenerationCompleted $event): void
    {
        $this->collector->endSpan($event);
    }

    public function handleStructuredOutputStarted(StructuredOutputStarted $event): void
    {
        $this->collector->startSpan($event);
    }

    public function handleStructuredOutputCompleted(StructuredOutputCompleted $event): void
    {
        $this->collector->endSpan($event);
    }

    public function handleEmbeddingGenerationStarted(EmbeddingGenerationStarted $event): void
    {
        $this->collector->startSpan($event);
    }

    public function handleEmbeddingGenerationCompleted(EmbeddingGenerationCompleted $event): void
    {
        $this->collector->endSpan($event);
    }

    public function handleStreamingStarted(StreamingStarted $event): void
    {
        $this->collector->startSpan($event);
    }

    public function handleStreamingCompleted(StreamingCompleted $event): void
    {
        $this->collector->endSpan($event);
    }

    public function handleHttpCallStarted(HttpCallStarted $event): void
    {
        $this->collector->startSpan($event);
    }

    public function handleHttpCallCompleted(HttpCallCompleted $event): void
    {
        $this->collector->endSpan($event);
    }

    public function handleToolCallStarted(ToolCallStarted $event): void
    {
        $this->collector->startSpan($event);
    }

    public function handleToolCallCompleted(ToolCallCompleted $event): void
    {
        $this->collector->endSpan($event);
    }

    public function handleSpanException(SpanException $event): void
    {
        $this->collector->recordException($event);
    }
}
