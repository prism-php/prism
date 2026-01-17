<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Otel;

use OpenTelemetry\SDK\Trace\IdGeneratorInterface;
use RuntimeException;

/**
 * An IdGenerator that must be "primed" with specific IDs before use.
 *
 * This allows the SDK's TracerProvider to use our exact IDs from SpanData
 * rather than generating new ones.
 */
class PrimedIdGenerator implements IdGeneratorInterface
{
    /** @var list<string> */
    private array $spanIds = [];

    /** @var list<string> */
    private array $traceIds = [];

    public function primeSpanId(string $spanId): void
    {
        $this->spanIds[] = $spanId;
    }

    public function primeTraceId(string $traceId): void
    {
        $this->traceIds[] = $traceId;
    }

    public function generateSpanId(): string
    {
        return array_shift($this->spanIds)
            ?? throw new RuntimeException('PrimedIdGenerator: spanId not primed. Call primeSpanId() before creating a span.');
    }

    public function generateTraceId(): string
    {
        return array_shift($this->traceIds)
            ?? throw new RuntimeException('PrimedIdGenerator: traceId not primed. Call primeTraceId() before creating a root span.');
    }
}
