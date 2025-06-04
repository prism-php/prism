# OpenTelemetry Driver Implementation Guide

This guide provides step-by-step instructions for implementing an OpenTelemetry driver for Prism's telemetry system.

## Overview

The OpenTelemetry driver will integrate with the existing telemetry architecture to export spans to OpenTelemetry-compatible backends like Jaeger, Zipkin, or cloud providers.

## Prerequisites

- Understanding of OpenTelemetry concepts (spans, traces, attributes)
- Knowledge of PHP and Laravel
- Familiarity with Prism's existing telemetry architecture

## Required Dependencies

Add the OpenTelemetry PHP packages to `composer.json`:

```bash
composer require open-telemetry/api
composer require open-telemetry/sdk
composer require open-telemetry/exporter-otlp
composer require open-telemetry/auto-http
```

## Implementation Steps

### 1. Create the OpenTelemetry Driver

Create `src/Telemetry/Drivers/OpenTelemetryDriver.php`:

```php
<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Telemetry\Drivers;

use EchoLabs\Prism\Telemetry\Contracts\Span;
use EchoLabs\Prism\Telemetry\Contracts\TelemetryDriver;
use EchoLabs\Prism\Telemetry\ValueObjects\OpenTelemetrySpan;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;

class OpenTelemetryDriver implements TelemetryDriver
{
    protected TracerInterface $tracer;

    public function __construct(
        TracerProviderInterface $tracerProvider,
        protected array $config = []
    ) {
        $this->tracer = $tracerProvider->getTracer(
            name: 'prism',
            version: '1.0.0',
            schemaUrl: 'https://opentelemetry.io/schemas/1.21.0'
        );
    }

    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        $spanBuilder = $this->tracer->spanBuilder($name);

        if ($startTime !== null) {
            $spanBuilder->setStartTimestamp((int) ($startTime * 1_000_000_000)); // Convert to nanoseconds
        }

        $otelSpan = $spanBuilder->startSpan();

        return new OpenTelemetrySpan($otelSpan, $attributes);
    }

    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $span = $this->startSpan($name, $attributes);

        try {
            $result = $callback($span);
            $span->setStatus(\EchoLabs\Prism\Telemetry\ValueObjects\SpanStatus::Ok);
            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(
                \EchoLabs\Prism\Telemetry\ValueObjects\SpanStatus::Error,
                $e->getMessage()
            );
            throw $e;
        } finally {
            $span->end();
        }
    }
}
```

### 2. Create the OpenTelemetry Span Implementation

Create `src/Telemetry/ValueObjects/OpenTelemetrySpan.php`:

```php
<?php

declare(strict_types=1);

namespace EchoLabs\Prism\Telemetry\ValueObjects;

use EchoLabs\Prism\Telemetry\Contracts\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

class OpenTelemetrySpan implements Span
{
    protected float $startTime;
    protected ?float $endTime = null;

    public function __construct(
        protected SpanInterface $otelSpan,
        array $attributes = []
    ) {
        $this->startTime = microtime(true);
        $this->setAttributes($attributes);
    }

    public function setAttribute(TelemetryAttribute|string $key, mixed $value): self
    {
        $attributeKey = $key instanceof TelemetryAttribute ? $key->value : $key;
        
        $this->otelSpan->setAttribute($attributeKey, $this->normalizeValue($value));
        
        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    public function addEvent(string $name, array $attributes = []): self
    {
        $normalizedAttributes = [];
        foreach ($attributes as $key => $value) {
            $attributeKey = $key instanceof TelemetryAttribute ? $key->value : $key;
            $normalizedAttributes[$attributeKey] = $this->normalizeValue($value);
        }

        $this->otelSpan->addEvent($name, $normalizedAttributes);

        return $this;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): self
    {
        $otelStatus = match ($status) {
            SpanStatus::Ok => StatusCode::STATUS_OK,
            SpanStatus::Error => StatusCode::STATUS_ERROR,
            SpanStatus::Timeout => StatusCode::STATUS_ERROR,
            SpanStatus::Cancelled => StatusCode::STATUS_ERROR,
        };

        $this->otelSpan->setStatus($otelStatus, $description);

        return $this;
    }

    public function end(?float $endTime = null): void
    {
        $this->endTime = $endTime ?? microtime(true);
        
        if ($endTime !== null) {
            $this->otelSpan->end((int) ($endTime * 1_000_000_000)); // Convert to nanoseconds
        } else {
            $this->otelSpan->end();
        }
    }

    public function isRecording(): bool
    {
        return $this->otelSpan->isRecording();
    }

    public function getName(): string
    {
        return $this->otelSpan->getName() ?? '';
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

        return $this->endTime - $this->startTime;
    }

    protected function normalizeValue(mixed $value): string|int|float|bool|null
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return json_encode($value);
        }

        return (string) $value;
    }
}
```

### 3. Add Driver Factory to TelemetryManager

Add the factory method to `src/Telemetry/TelemetryManager.php`:

```php
public function createOpentelemetryDriver(): OpenTelemetryDriver
{
    $config = $this->config->get('prism.telemetry.drivers.opentelemetry', []);
    
    $tracerProvider = $this->createTracerProvider($config);
    
    return new OpenTelemetryDriver($tracerProvider, $config);
}

protected function createTracerProvider(array $config): \OpenTelemetry\API\Trace\TracerProviderInterface
{
    $resourceBuilder = \OpenTelemetry\SDK\Resource\ResourceInfoFactory::emptyResource()
        ->merge(\OpenTelemetry\SDK\Resource\ResourceInfo::create([
            'service.name' => $config['service_name'] ?? 'prism',
            'service.version' => $config['service_version'] ?? '1.0.0',
        ]));

    $spanProcessor = $this->createSpanProcessor($config);

    return \OpenTelemetry\SDK\Trace\TracerProviderBuilder::create()
        ->addSpanProcessor($spanProcessor)
        ->setResource($resourceBuilder)
        ->build();
}

protected function createSpanProcessor(array $config): \OpenTelemetry\SDK\Trace\SpanProcessorInterface
{
    $exporter = $this->createExporter($config);
    
    // Use batch processor for better performance
    return new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(
        $exporter,
        \OpenTelemetry\Context\Context::getCurrent()
    );
}

protected function createExporter(array $config): \OpenTelemetry\SDK\Trace\SpanExporterInterface
{
    $endpoint = $config['endpoint'] ?? 'http://localhost:4318/v1/traces';
    $headers = $config['headers'] ?? [];
    
    // Parse headers if provided as string
    if (is_string($headers)) {
        $parsedHeaders = [];
        foreach (explode(',', $headers) as $header) {
            [$key, $value] = explode('=', $header, 2);
            $parsedHeaders[trim($key)] = trim($value);
        }
        $headers = $parsedHeaders;
    }

    $transport = \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory::create(
        $endpoint,
        'application/x-protobuf',
        $headers
    );

    return new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport);
}
```

### 4. Update Configuration

Add the OpenTelemetry driver configuration to `config/prism.php`:

```php
'telemetry' => [
    'enabled' => env('PRISM_TELEMETRY_ENABLED', true),
    'default' => env('PRISM_TELEMETRY_DRIVER', 'null'),
    
    'drivers' => [
        'null' => ['driver' => 'null'],
        'log' => [
            'driver' => 'log',
            'channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'default'),
            'level' => env('PRISM_TELEMETRY_LOG_LEVEL', 'info'),
            'include_attributes' => true,
        ],
        'opentelemetry' => [
            'driver' => 'opentelemetry',
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces'),
            'headers' => env('OTEL_EXPORTER_OTLP_HEADERS', ''),
            'service_name' => env('OTEL_SERVICE_NAME', 'prism'),
            'service_version' => env('OTEL_SERVICE_VERSION', '1.0.0'),
        ],
    ],
],
```

### 5. Environment Configuration

Add these environment variables to your `.env` file:

```env
# OpenTelemetry Configuration
PRISM_TELEMETRY_DRIVER=opentelemetry
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318/v1/traces
OTEL_EXPORTER_OTLP_HEADERS=
OTEL_SERVICE_NAME=prism
OTEL_SERVICE_VERSION=1.0.0

# For cloud providers, you might need authentication headers:
# OTEL_EXPORTER_OTLP_HEADERS="authorization=Bearer your-token,x-api-key=your-key"
```

## Testing the Implementation

### 1. Create Unit Tests

Create `tests/Telemetry/Drivers/OpenTelemetryDriverTest.php`:

```php
<?php

declare(strict_types=1);

use EchoLabs\Prism\Telemetry\Drivers\OpenTelemetryDriver;
use EchoLabs\Prism\Telemetry\ValueObjects\SpanStatus;
use EchoLabs\Prism\Telemetry\ValueObjects\TelemetryAttribute;
use OpenTelemetry\API\Trace\TracerProviderInterface;

it('creates spans with correct attributes', function () {
    $tracerProvider = Mockery::mock(TracerProviderInterface::class);
    $tracer = Mockery::mock(\OpenTelemetry\API\Trace\TracerInterface::class);
    $otelSpan = Mockery::mock(\OpenTelemetry\API\Trace\SpanInterface::class);
    
    $tracerProvider->shouldReceive('getTracer')->andReturn($tracer);
    $tracer->shouldReceive('spanBuilder->startSpan')->andReturn($otelSpan);
    
    $driver = new OpenTelemetryDriver($tracerProvider);
    
    $span = $driver->startSpan('test-operation', [
        TelemetryAttribute::ProviderName->value => 'openai',
        'custom.attribute' => 'value',
    ]);
    
    expect($span)->toBeInstanceOf(\EchoLabs\Prism\Telemetry\ValueObjects\OpenTelemetrySpan::class);
});

it('handles span execution with callback', function () {
    $tracerProvider = Mockery::mock(TracerProviderInterface::class);
    $driver = new OpenTelemetryDriver($tracerProvider);
    
    $result = $driver->span('test-operation', [], function ($span) {
        expect($span)->toBeInstanceOf(\EchoLabs\Prism\Telemetry\Contracts\Span::class);
        return 'success';
    });
    
    expect($result)->toBe('success');
});
```

### 2. Integration Tests

Create `tests/Telemetry/OpenTelemetryIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

use EchoLabs\Prism\Facades\PrismServer;
use EchoLabs\Prism\Telemetry\Facades\Telemetry;

it('exports telemetry data to OpenTelemetry backend', function () {
    config(['prism.telemetry.default' => 'opentelemetry']);
    
    Telemetry::span('test-operation', [
        'test.attribute' => 'value',
    ], function ($span) {
        $span->addEvent('test-event');
        $span->setAttribute('operation.result', 'success');
    });
    
    // Verify the span was created and exported
    // This would require setting up a test OTLP receiver
});
```

## Deployment Considerations

### Production Configuration

1. **Use OTLP/HTTP for reliability**:
   ```env
   OTEL_EXPORTER_OTLP_ENDPOINT=https://your-collector.example.com/v1/traces
   ```

2. **Configure authentication**:
   ```env
   OTEL_EXPORTER_OTLP_HEADERS="authorization=Bearer your-api-token"
   ```

3. **Set appropriate service metadata**:
   ```env
   OTEL_SERVICE_NAME=prism-production
   OTEL_SERVICE_VERSION=1.2.3
   ```

### Popular Backend Integrations

#### Jaeger
```env
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger-collector:14268/api/traces
```

#### Zipkin
```env
OTEL_EXPORTER_OTLP_ENDPOINT=http://zipkin:9411/api/v2/spans
```

#### Cloud Providers

**AWS X-Ray** (via OpenTelemetry Collector):
```env
OTEL_EXPORTER_OTLP_ENDPOINT=http://aws-otel-collector:4318/v1/traces
```

**Google Cloud Trace**:
```env
OTEL_EXPORTER_OTLP_ENDPOINT=https://cloudtrace.googleapis.com/v1/projects/PROJECT_ID/traces
OTEL_EXPORTER_OTLP_HEADERS="authorization=Bearer gcp-token"
```

**Azure Monitor**:
```env
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-app.applicationinsights.azure.com/v1/traces
OTEL_EXPORTER_OTLP_HEADERS="x-api-key=your-instrumentation-key"
```

## Performance Optimization

1. **Use batch span processor** for better throughput
2. **Configure appropriate flush intervals** based on your needs
3. **Consider sampling** for high-volume applications
4. **Monitor memory usage** with large span payloads

## Troubleshooting

### Common Issues

1. **Connection refused**: Verify the OTLP endpoint is accessible
2. **Authentication failures**: Check headers and credentials
3. **High memory usage**: Reduce batch size or enable sampling
4. **Missing spans**: Verify the tracer provider is properly configured

### Debug Mode

Enable debug logging to troubleshoot issues:

```php
'opentelemetry' => [
    'driver' => 'opentelemetry',
    'debug' => env('OTEL_DEBUG', false),
    // ... other config
],
```

## Security Considerations

1. **Secure OTLP endpoints** with HTTPS in production
2. **Protect authentication tokens** in environment variables
3. **Avoid logging sensitive data** in span attributes
4. **Use service accounts** for cloud provider authentication

## Next Steps

After implementing the basic OpenTelemetry driver:

1. Add support for distributed tracing with trace context propagation
2. Implement custom sampling strategies
3. Add metrics collection support
4. Create middleware for automatic HTTP request tracing
5. Add support for custom resource attributes

This implementation provides a solid foundation for OpenTelemetry integration while following Prism's existing architectural patterns.