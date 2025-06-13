<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Contracts\TelemetryDriver;

class LogDriver implements TelemetryDriver
{
    public function __construct(
        protected string $channel = 'default'
    ) {}

    public function startSpan(string $operation, array $attributes = []): string
    {
        $spanId = Str::uuid()->toString();

        Log::channel($this->channel)->info('Span started', [
            'span_id' => $spanId,
            'operation' => $operation,
            'attributes' => $attributes,
            'timestamp' => now()->toISOString(),
        ]);

        return $spanId;
    }

    public function endSpan(string $spanId, array $attributes = []): void
    {
        Log::channel($this->channel)->info('Span ended', [
            'span_id' => $spanId,
            'attributes' => $attributes,
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function addEvent(string $spanId, string $name, array $attributes = []): void
    {
        Log::channel($this->channel)->info('Span event', [
            'span_id' => $spanId,
            'event_name' => $name,
            'attributes' => $attributes,
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function recordException(string $spanId, \Throwable $exception): void
    {
        Log::channel($this->channel)->error('Span exception', [
            'span_id' => $spanId,
            'exception' => [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ],
            'timestamp' => now()->toISOString(),
        ]);
    }
}
