<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\Prism\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Contracts\SemanticMapperInterface;
use Prism\Prism\Telemetry\Otel\PrimedIdGenerator;
use Prism\Prism\Telemetry\Semantics\PassthroughMapper;
use Prism\Prism\Telemetry\SpanData;

class OtlpDriver implements TelemetryDriver
{
    protected ?TracerProvider $provider = null;

    protected ?PrimedIdGenerator $idGenerator = null;

    protected SemanticMapperInterface $mapper;

    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(protected string $driver = 'otlp')
    {
        $this->config = config("prism.telemetry.drivers.{$this->driver}", []);

        /** @var class-string<SemanticMapperInterface> $mapperClass */
        $mapperClass = $this->config['mapper'] ?? PassthroughMapper::class;
        $this->mapper = new $mapperClass;
    }

    public function recordSpan(SpanData $spanData): void
    {
        $this->idGenerator()->primeSpanId($spanData->spanId);

        if (! $spanData->parentSpanId) {
            $this->idGenerator()->primeTraceId($spanData->traceId);
        }

        $spanBuilder = $this->provider()->getTracer('prism', '1.0.0')
            ->spanBuilder($spanData->operation ?: 'unknown')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setStartTimestamp($spanData->startTimeNano);

        if ($spanData->parentSpanId) {
            $spanBuilder->setParent(Context::getCurrent()->withContextValue(
                Span::wrap(SpanContext::create($spanData->traceId, $spanData->parentSpanId, TraceFlags::SAMPLED))
            ));
        }

        $span = $spanBuilder->startSpan();

        $attributes = $spanData->attributes;

        if ($tags = $this->config['tags'] ?? null) {
            data_set($attributes, 'metadata.tags', $tags);
        }

        foreach ($this->mapper->map($spanData->operation, $attributes) as $key => $value) {
            if ($key !== '') {
                $span->setAttribute($key, is_array($value) ? json_encode($value) : $value);
            }
        }

        foreach ($this->mapper->mapEvents($spanData->events) as $event) {
            $span->addEvent($event['name'], Attributes::create($event['attributes']), $event['timeNanos']);
        }

        if ($spanData->hasError()) {
            $span->setStatus(StatusCode::STATUS_ERROR, $spanData->exception?->getMessage() ?? 'Error');
        }

        $span->end($spanData->endTimeNano);
    }

    public function shutdown(): void
    {
        $this->provider?->shutdown();
        $this->provider = null;
        $this->idGenerator = null;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    protected function provider(): TracerProvider
    {
        if ($this->provider instanceof \OpenTelemetry\SDK\Trace\TracerProvider) {
            return $this->provider;
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            endpoint: $this->config['endpoint'] ?? 'http://localhost:4318/v1/traces',
            contentType: ContentTypes::JSON,
            headers: ($key = $this->config['api_key'] ?? null) ? ['Authorization' => "Bearer {$key}"] : [],
            timeout: (float) ($this->config['timeout'] ?? 30.0),
        );

        return $this->provider = new TracerProvider(
            spanProcessors: [new BatchSpanProcessor(new SpanExporter($transport), Clock::getDefault())],
            sampler: new AlwaysOnSampler,
            resource: ResourceInfo::create(Attributes::create(['service.name' => $this->config['service_name'] ?? 'prism'])),
            idGenerator: $this->idGenerator(),
        );
    }

    protected function idGenerator(): PrimedIdGenerator
    {
        return $this->idGenerator ??= new PrimedIdGenerator;
    }
}
