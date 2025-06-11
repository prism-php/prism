<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Contracts;

use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;

interface Span
{
    public function setAttribute(TelemetryAttribute|string $key, mixed $value): self;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setAttributes(array $attributes): self;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addEvent(string $name, array $attributes = []): self;

    public function setStatus(SpanStatus $status, ?string $description = null): self;

    public function end(?float $endTime = null): void;

    public function isRecording(): bool;

    public function getName(): string;

    public function getStartTime(): float;

    public function getDuration(): ?float;
}
