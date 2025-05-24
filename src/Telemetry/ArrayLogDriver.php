<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Support\Str;
use Prism\Prism\Contracts\Telemetry;

class ArrayLogDriver implements Telemetry
{
    /**
     * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    protected array $logs = [];

    public function __construct(
        protected bool $enabled = true
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): T  $callback
     * @return T
     */
    public function span(string $spanName, array $attributes, callable $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }

        $spanId = $this->generateSpanId();
        $startTime = microtime(true);

        $this->logSpanStart($spanName, $spanId, $attributes, $startTime);

        try {
            $result = $callback();
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds

            $this->logSpanEnd($spanName, $spanId, $attributes, $duration, 'success');

            return $result;
        } catch (\Throwable $e) {
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds

            $this->logSpanEnd($spanName, $spanId, $attributes, $duration, 'error', $e);

            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     * @param  callable(): T  $callback
     * @return T
     */
    public function childSpan(string $spanName, array $attributes, callable $callback): mixed
    {
        // For the array driver, child spans are handled the same as regular spans
        // but we add a marker to indicate it's a child span
        return $this->span($spanName, array_merge($attributes, [
            'prism.telemetry.span_type' => 'child',
        ]), $callback);
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clearLogs(): void
    {
        $this->logs = [];
    }

    protected function generateSpanId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     */
    protected function logSpanStart(string $spanName, string $spanId, array $attributes, float $startTime): void
    {
        $context = [
            'prism.telemetry.span_name' => $spanName,
            'prism.telemetry.span_id' => $spanId,
            'prism.telemetry.event' => 'span.start',
            'prism.telemetry.timestamp' => $startTime,
            ...$attributes,
        ];

        $this->logs[] = [
            'level' => 'info',
            'message' => "Prism span started: {$spanName}",
            'context' => $context,
        ];
    }

    /**
     * @param  non-empty-string  $spanName
     * @param  array<non-empty-string, mixed>  $attributes
     */
    protected function logSpanEnd(
        string $spanName,
        string $spanId,
        array $attributes,
        float $duration,
        string $status,
        ?\Throwable $exception = null
    ): void {
        $context = [
            'prism.telemetry.span_name' => $spanName,
            'prism.telemetry.span_id' => $spanId,
            'prism.telemetry.event' => 'span.end',
            'prism.telemetry.duration_ms' => round($duration, 2),
            'prism.telemetry.status' => $status,
            ...$attributes,
        ];

        if ($exception instanceof \Throwable) {
            $context['prism.telemetry.error.class'] = $exception::class;
            $context['prism.telemetry.error.message'] = $exception->getMessage();
            $context['prism.telemetry.error.file'] = $exception->getFile();
            $context['prism.telemetry.error.line'] = $exception->getLine();
        }

        $durationMs = (string) $context['prism.telemetry.duration_ms'];
        $message = $status === 'success'
            ? "Prism span completed: {$spanName} ({$durationMs}ms)"
            : "Prism span failed: {$spanName} ({$durationMs}ms)";

        $this->logs[] = [
            'level' => 'info',
            'message' => $message,
            'context' => $context,
        ];
    }
}
