<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Otel;

use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SDK\Trace\StatusDataInterface;

/**
 * Adapts Prism's span data to OpenTelemetry's SpanDataInterface.
 *
 * This allows us to use OTEL SDK exporters with our custom span data.
 */
class SpanDataAdapter implements SpanDataInterface
{
    private readonly SpanContextInterface $context;

    private readonly SpanContextInterface $parentContext;

    private readonly StatusDataInterface $status;

    /**
     * OpenTelemetry SDK's AttributesInterface lacks generic type annotations.
     *
     * @phpstan-ignore missingType.iterableValue
     */
    private readonly AttributesInterface $attributes;

    /** @var list<Event> */
    private readonly array $events;

    private readonly InstrumentationScopeInterface $instrumentationScope;

    private readonly ResourceInfo $resource;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{name: string, timeNano: int, attributes: array<string, mixed>}>  $events
     */
    public function __construct(
        private readonly string $name,
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId,
        private readonly int $startTimeNano,
        private readonly int $endTimeNano,
        array $attributes,
        array $events,
        bool $hasError,
        string $serviceName = 'prism',
    ) {
        $this->context = SpanContext::create($traceId, $spanId, TraceFlags::SAMPLED);

        $this->parentContext = $parentSpanId
            ? SpanContext::create($traceId, $parentSpanId, TraceFlags::SAMPLED)
            : SpanContext::getInvalid();

        $this->status = $hasError
            ? StatusData::create(StatusCode::STATUS_ERROR)
            : StatusData::create(StatusCode::STATUS_OK);

        $this->attributes = Attributes::create($attributes);

        $this->events = array_values(array_map(
            fn (array $event): Event => new Event(
                $event['name'],
                $event['timeNano'],
                Attributes::create($event['attributes'])
            ),
            $events
        ));

        $this->instrumentationScope = new InstrumentationScope(
            'prism',
            '1.0.0',
            null,
            Attributes::create([])
        );

        $this->resource = ResourceInfo::create(Attributes::create([
            'service.name' => $serviceName,
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.name' => 'prism',
        ]));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): int
    {
        return SpanKind::KIND_INTERNAL;
    }

    public function getContext(): SpanContextInterface
    {
        return $this->context;
    }

    public function getParentContext(): SpanContextInterface
    {
        return $this->parentContext;
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function getParentSpanId(): string
    {
        return $this->parentSpanId ?? '';
    }

    public function getStatus(): StatusDataInterface
    {
        return $this->status;
    }

    public function getStartEpochNanos(): int
    {
        return $this->startTimeNano;
    }

    public function getEndEpochNanos(): int
    {
        return $this->endTimeNano;
    }

    /**
     * OpenTelemetry SDK's AttributesInterface lacks generic type annotations.
     *
     * @phpstan-ignore missingType.iterableValue
     */
    public function getAttributes(): AttributesInterface
    {
        return $this->attributes;
    }

    /** @return list<Event> */
    public function getEvents(): array
    {
        return $this->events;
    }

    /** @return list<never> */
    public function getLinks(): array
    {
        return [];
    }

    public function hasEnded(): bool
    {
        return true;
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }

    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }

    public function getTotalDroppedEvents(): int
    {
        return 0;
    }

    public function getTotalDroppedLinks(): int
    {
        return 0;
    }
}
