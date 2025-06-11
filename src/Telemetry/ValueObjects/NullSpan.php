<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

use Prism\Prism\Telemetry\Contracts\Span;

class NullSpan implements Span
{
    public function __construct(
        private readonly string $name,
        private readonly float $startTime = 0.0
    ) {}

    public function setAttribute(TelemetryAttribute|string $key, mixed $value): self
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): self
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): self
    {
        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): self
    {
        return $this;
    }

    public function end(?float $endTime = null): void
    {
        // No-op
    }

    public function isRecording(): bool
    {
        return false;
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
        return null;
    }
}
