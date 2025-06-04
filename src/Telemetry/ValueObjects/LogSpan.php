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

        Log::channel($this->logChannel)->log($this->logLevel, 'Span started', [
            'span.id' => $this->spanId,
            'span.name' => $this->name,
            'span.start_time' => $this->startTime,
        ]);
    }

    public function setAttribute(TelemetryAttribute|string $key, mixed $value): self
    {
        $keyString = $key instanceof TelemetryAttribute ? $key->value : $key;
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

        $logData = [
            'span.id' => $this->spanId,
            'span.name' => $this->name,
            'span.duration_ms' => round($duration, 2),
            'span.status' => $this->status?->value ?? 'ok',
            'attributes' => $this->attributes,
        ];

        if ($this->statusDescription) {
            $logData['span.status_description'] = $this->statusDescription;
        }

        if ($this->events !== []) {
            $logData['span.events'] = $this->events;
        }

        $logLevel = $this->status === SpanStatus::Error ? 'error' : $this->logLevel;

        Log::channel($this->logChannel)->log($logLevel, 'Span completed', $logData);
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
