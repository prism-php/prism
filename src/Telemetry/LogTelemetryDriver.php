<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Prism\Prism\Contracts\TelemetryDriver;
use Psr\Log\LoggerInterface;

class LogTelemetryDriver implements TelemetryDriver
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $activeSpans = [];

    public function __construct(
        protected LoggerInterface $logger
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?string $parentId = null): string
    {
        $spanId = $this->generateSpanId();

        $this->activeSpans[$spanId] = [
            'name' => $name,
            'start_time' => microtime(true),
            'parent_id' => $parentId,
            'attributes' => $attributes,
        ];

        $context = [
            'span_id' => $spanId,
            'span_name' => $name,
            'parent_span_id' => $parentId,
            'attributes' => $attributes,
        ];

        $this->logger->info('Telemetry span started', $context);

        return $spanId;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function endSpan(string $contextId, array $attributes = []): void
    {
        if (! isset($this->activeSpans[$contextId])) {
            return;
        }

        $span = $this->activeSpans[$contextId];
        $duration = (microtime(true) - $span['start_time']) * 1000;

        $context = [
            'span_id' => $contextId,
            'span_name' => $span['name'],
            'parent_span_id' => $span['parent_id'],
            'duration_ms' => $duration,
            'start_attributes' => $span['attributes'],
            'end_attributes' => $attributes,
        ];

        $this->logger->info('Telemetry span completed', $context);

        unset($this->activeSpans[$contextId]);
    }

    public function recordException(string $contextId, \Throwable $exception): void
    {
        if (! isset($this->activeSpans[$contextId])) {
            return;
        }

        $span = $this->activeSpans[$contextId];

        $context = [
            'span_id' => $contextId,
            'span_name' => $span['name'],
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
        ];

        $this->logger->error('Telemetry span exception', $context);
    }

    protected function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
