<?php

declare(strict_types=1);

namespace Prism\Prism\Listeners;

use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Events\HttpRequestCompleted;
use Prism\Prism\Events\HttpRequestStarted;
use Prism\Prism\Events\PrismRequestCompleted;
use Prism\Prism\Events\PrismRequestStarted;
use Prism\Prism\Events\TelemetryEvent;
use Prism\Prism\Events\ToolCallCompleted;
use Prism\Prism\Events\ToolCallStarted;

class TelemetryEventListener
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $activeSpans = [];

    public function __construct(
        protected TelemetryDriver $driver,
        protected bool $enabled = true
    ) {}

    public function handle(TelemetryEvent $event): void
    {
        if (! $this->enabled) {
            return;
        }

        match ($event::class) {
            PrismRequestStarted::class => $this->handlePrismRequestStarted($event),
            PrismRequestCompleted::class => $this->handlePrismRequestCompleted($event),
            HttpRequestStarted::class => $this->handleHttpRequestStarted($event),
            HttpRequestCompleted::class => $this->handleHttpRequestCompleted($event),
            ToolCallStarted::class => $this->handleToolCallStarted($event),
            ToolCallCompleted::class => $this->handleToolCallCompleted($event),
            default => null,
        };
    }

    protected function handlePrismRequestStarted(PrismRequestStarted $event): void
    {
        $spanId = $this->driver->startSpan(
            name: $event->operationName,
            attributes: $event->attributes,
            parentId: null
        );

        $this->activeSpans[$event->contextId] = [
            'spanId' => $spanId,
            'type' => 'prism_request',
            'startTime' => microtime(true),
        ];
    }

    protected function handlePrismRequestCompleted(PrismRequestCompleted $event): void
    {
        if (! isset($this->activeSpans[$event->contextId])) {
            return;
        }

        $span = $this->activeSpans[$event->contextId];

        $attributes = array_merge($event->attributes, [
            'duration_ms' => (microtime(true) - $span['startTime']) * 1000,
        ]);

        if ($event->exception instanceof \Throwable) {
            $this->driver->recordException($span['spanId'], $event->exception);
        }

        $this->driver->endSpan($span['spanId'], $attributes);
        unset($this->activeSpans[$event->contextId]);
    }

    protected function handleHttpRequestStarted(HttpRequestStarted $event): void
    {
        $parentSpanId = $this->getParentSpanId($event->parentContextId);

        $spanId = $this->driver->startSpan(
            name: 'http_request',
            attributes: array_merge($event->attributes, [
                'http.method' => $event->method,
                'http.url' => $event->url,
                'provider.name' => $event->provider,
            ]),
            parentId: $parentSpanId
        );

        $this->activeSpans[$event->contextId] = [
            'spanId' => $spanId,
            'type' => 'http_request',
            'startTime' => microtime(true),
        ];
    }

    protected function handleHttpRequestCompleted(HttpRequestCompleted $event): void
    {
        if (! isset($this->activeSpans[$event->contextId])) {
            return;
        }

        $span = $this->activeSpans[$event->contextId];

        $attributes = array_merge($event->attributes, [
            'http.status_code' => $event->statusCode,
            'duration_ms' => (microtime(true) - $span['startTime']) * 1000,
        ]);

        if ($event->exception instanceof \Throwable) {
            $this->driver->recordException($span['spanId'], $event->exception);
        }

        $this->driver->endSpan($span['spanId'], $attributes);
        unset($this->activeSpans[$event->contextId]);
    }

    protected function handleToolCallStarted(ToolCallStarted $event): void
    {
        $parentSpanId = $this->getParentSpanId($event->parentContextId);

        $spanId = $this->driver->startSpan(
            name: 'tool_call',
            attributes: array_merge($event->attributes, [
                'tool.name' => $event->toolName,
                'tool.parameters' => json_encode($event->parameters),
            ]),
            parentId: $parentSpanId
        );

        $this->activeSpans[$event->contextId] = [
            'spanId' => $spanId,
            'type' => 'tool_call',
            'startTime' => microtime(true),
        ];
    }

    protected function handleToolCallCompleted(ToolCallCompleted $event): void
    {
        if (! isset($this->activeSpans[$event->contextId])) {
            return;
        }

        $span = $this->activeSpans[$event->contextId];

        $attributes = array_merge($event->attributes, [
            'duration_ms' => (microtime(true) - $span['startTime']) * 1000,
        ]);

        if ($event->exception instanceof \Throwable) {
            $this->driver->recordException($span['spanId'], $event->exception);
        }

        $this->driver->endSpan($span['spanId'], $attributes);
        unset($this->activeSpans[$event->contextId]);
    }

    protected function getParentSpanId(?string $parentContextId): ?string
    {
        if (! $parentContextId || ! isset($this->activeSpans[$parentContextId])) {
            return null;
        }

        return $this->activeSpans[$parentContextId]['spanId'];
    }
}
