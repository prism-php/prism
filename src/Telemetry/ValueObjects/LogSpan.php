<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Telemetry\Contracts\Span;

class LogSpan implements Span
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    private ?SpanStatus $status = null;

    private ?string $statusDescription = null;

    private ?float $endTime = null;

    private readonly string $spanId;

    public function __construct(
        private readonly string $name,
        private readonly float $startTime,
        private readonly string $logChannel,
        private readonly string $logLevel
    ) {
        $this->spanId = Str::uuid()->toString();

        // Log span start with span name as the message
        Log::channel($this->logChannel)->log($this->logLevel, $this->name, [
            'span.id' => $this->spanId,
            'span.phase' => 'start',
            'span.start_time' => $this->startTime,
        ]);
    }

    public function setAttribute(\Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute|string $key, mixed $value): self
    {
        $keyString = $key instanceof \Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute ? $key->value : $key;
        $this->attributes[$keyString] = $value;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): self
    {
        $this->events[] = [
            'name' => $name,
            'attributes' => $attributes,
            'timestamp' => microtime(true),
        ];

        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): self
    {
        $this->status = $status;
        $this->statusDescription = $description;

        return $this;
    }

    public function end(?float $endTime = null): void
    {
        if ($this->endTime !== null) {
            return; // Already ended
        }

        $this->endTime = $endTime ?? microtime(true);
        $duration = ($this->endTime - $this->startTime) * 1000; // Convert to milliseconds

        // Build context starting with span metadata
        $context = [
            'span.id' => $this->spanId,
            'span.phase' => 'end',
            'span.duration_ms' => round($duration, 2),
            'span.status' => $this->status?->value ?? 'ok',
        ];

        // Add all attributes directly to the context
        $context = array_merge($context, $this->attributes);

        if ($this->statusDescription) {
            $context['span.status_description'] = $this->statusDescription;
        }

        if ($this->events !== []) {
            $context['span.events'] = $this->events;
        }

        $logLevel = $this->status === SpanStatus::Error ? 'error' : $this->logLevel;

        // Use span name as the log message, all data as context
        Log::channel($this->logChannel)->log($logLevel, $this->name, $context);
    }

    public function isRecording(): bool
    {
        return $this->endTime === null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getDuration(): ?float
    {
        if ($this->endTime === null) {
            return null;
        }

        return ($this->endTime - $this->startTime) * 1000; // milliseconds
    }
}
