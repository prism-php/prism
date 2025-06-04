# Prism Telemetry Implementation Guide

## Overview

This document outlines the implementation of a telemetry system for Prism using Laravel's driver pattern. The telemetry system will track spans (operations with duration) and attributes (metadata) for AI provider interactions, tool executions, and request processing.

## Goals

- **Extensible**: People can extend and customize drivers, spans, and behaviors
- **Laravel-native**: Follow Laravel conventions and patterns
- **Driver-based**: Support multiple telemetry backends (null, log, future OpenTelemetry)
- **Performance-conscious**: Zero overhead when disabled
- **Type-safe**: Use modern PHP features (enums, strict types)
- **Observable**: Rich data for debugging and monitoring

## Architecture Overview

```
TelemetryManager (extends Manager)
├── Drivers/
│   ├── NullDriver (no-op implementation)
│   ├── LogDriver (structured logging)
│   └── [Future] OpenTelemetryDriver
├── Contracts/
│   ├── TelemetryDriver
│   └── Span
├── ValueObjects/
│   ├── SpanStatus (enum)
│   └── TelemetryAttribute (enum)
└── Facades/
    └── Telemetry
```

## File Structure

Create the following files in the Prism package:

```
src/
├── Telemetry/
│   ├── TelemetryManager.php
│   ├── Contracts/
│   │   ├── TelemetryDriver.php
│   │   └── Span.php
│   ├── Drivers/
│   │   ├── NullDriver.php
│   │   └── LogDriver.php
│   ├── ValueObjects/
│   │   ├── SpanStatus.php
│   │   ├── TelemetryAttribute.php
│   │   ├── NullSpan.php
│   │   └── LogSpan.php
│   └── Facades/
│       └── Telemetry.php
```

## Implementation Steps

### Step 1: Create Core Enums and Value Objects

#### TelemetryAttribute Enum
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

enum TelemetryAttribute: string
{
    // Provider attributes
    case ProviderName = 'prism.provider.name';
    case ProviderModel = 'prism.provider.model';
    
    // Request attributes  
    case RequestType = 'prism.request.type';
    case RequestTokensInput = 'prism.request.tokens.input';
    case RequestTokensOutput = 'prism.request.tokens.output';
    case RequestDuration = 'prism.request.duration_ms';
    
    // Tool attributes
    case ToolName = 'prism.tool.name';
    case ToolSuccess = 'prism.tool.success';
    case ToolDuration = 'prism.tool.duration_ms';
    
    // Error attributes
    case ErrorType = 'prism.error.type';
    case ErrorMessage = 'prism.error.message';
    case ErrorCode = 'prism.error.code';
    
    // Stream attributes
    case StreamChunksTotal = 'prism.stream.chunks.total';
    case StreamTokensPerSecond = 'prism.stream.tokens_per_second';
}
```

#### SpanStatus Enum
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

enum SpanStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Timeout = 'timeout';
    case Cancelled = 'cancelled';
}
```

### Step 2: Create Contracts

#### Span Contract
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Contracts;

use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Prism\Prism\Telemetry\ValueObjects\TelemetryAttribute;

interface Span
{
    public function setAttribute(TelemetryAttribute|string $key, mixed $value): self;
    
    public function setAttributes(array $attributes): self;
    
    public function addEvent(string $name, array $attributes = []): self;
    
    public function setStatus(SpanStatus $status, ?string $description = null): self;
    
    public function end(?float $endTime = null): void;
    
    public function isRecording(): bool;
    
    public function getName(): string;
    
    public function getStartTime(): float;
    
    public function getDuration(): ?float;
}
```

#### TelemetryDriver Contract
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Contracts;

interface TelemetryDriver
{
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span;
    
    public function span(string $name, array $attributes, callable $callback): mixed;
    
    public function isEnabled(): bool;
}
```

### Step 3: Implement Span Implementations

#### NullSpan (No-op Implementation)
```php
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

    public function setAttributes(array $attributes): self
    {
        return $this;
    }

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
```

#### LogSpan (Logging Implementation)
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\ValueObjects;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Telemetry\Contracts\Span;

class LogSpan implements Span
{
    private array $attributes = [];
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

    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

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

        if (!empty($this->events)) {
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
```

### Step 4: Implement Drivers

#### NullDriver
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\ValueObjects\NullSpan;

class NullDriver implements TelemetryDriver
{
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        return new NullSpan($name, $startTime ?? microtime(true));
    }

    public function span(string $name, array $attributes, callable $callback): mixed
    {
        return $callback();
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
```

#### LogDriver
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Drivers;

use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\ValueObjects\LogSpan;
use Prism\Prism\Telemetry\ValueObjects\SpanStatus;
use Throwable;

class LogDriver implements TelemetryDriver
{
    public function __construct(
        private readonly string $logChannel,
        private readonly string $logLevel,
        private readonly bool $includeAttributes
    ) {}

    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        $span = new LogSpan(
            $name,
            $startTime ?? microtime(true),
            $this->logChannel,
            $this->logLevel
        );

        if ($this->includeAttributes && !empty($attributes)) {
            $span->setAttributes($attributes);
        }

        return $span;
    }

    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $span = $this->startSpan($name, $attributes);

        try {
            $result = $callback();
            $span->setStatus(SpanStatus::Ok);
            return $result;
        } catch (Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
```

### Step 5: Create Manager

#### TelemetryManager
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry;

use Illuminate\Support\Manager;
use Prism\Prism\Telemetry\Contracts\Span;
use Prism\Prism\Telemetry\Contracts\TelemetryDriver;
use Prism\Prism\Telemetry\Drivers\LogDriver;
use Prism\Prism\Telemetry\Drivers\NullDriver;

/**
 * @mixin TelemetryDriver
 */
class TelemetryManager extends Manager
{
    private ?Span $currentSpan = null;

    public function getDefaultDriver(): string
    {
        return $this->config->get('prism.telemetry.default', 'null');
    }

    public function createNullDriver(): NullDriver
    {
        return new NullDriver();
    }

    public function createLogDriver(array $config = []): LogDriver
    {
        return new LogDriver(
            logChannel: $config['channel'] ?? 'default',
            logLevel: $config['level'] ?? 'info',
            includeAttributes: $config['include_attributes'] ?? true
        );
    }

    public function enabled(): bool
    {
        return $this->config->get('prism.telemetry.enabled', true) 
            && $this->driver()->isEnabled();
    }

    public function current(): ?Span
    {
        return $this->currentSpan;
    }

    public function withCurrentSpan(Span $span, callable $callback): mixed
    {
        $previousSpan = $this->currentSpan;
        $this->currentSpan = $span;

        try {
            return $callback();
        } finally {
            $this->currentSpan = $previousSpan;
        }
    }

    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        if (!$this->enabled()) {
            return $this->createNullDriver()->startSpan($name, $attributes, $startTime);
        }

        return $this->driver()->startSpan($name, $attributes, $startTime);
    }

    public function span(string $name, array $attributes, callable $callback): mixed
    {
        if (!$this->enabled()) {
            return $callback();
        }

        $span = $this->startSpan($name, $attributes);
        
        return $this->withCurrentSpan($span, function () use ($span, $callback) {
            return $this->driver()->span($span->getName(), $attributes, $callback);
        });
    }
}
```

### Step 6: Create Facade

#### Telemetry Facade
```php
<?php

declare(strict_types=1);

namespace Prism\Prism\Telemetry\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Prism\Telemetry\Contracts\Span;

/**
 * @method static Span startSpan(string $name, array $attributes = [], ?float $startTime = null)
 * @method static mixed span(string $name, array $attributes, callable $callback)
 * @method static bool enabled()
 * @method static Span|null current()
 * @method static mixed withCurrentSpan(Span $span, callable $callback)
 */
class Telemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'prism-telemetry';
    }
}
```

### Step 7: Update Service Provider

Add telemetry registration to `PrismServiceProvider`:

```php
public function register(): void
{
    // ... existing registrations ...

    $this->app->singleton(
        'prism-telemetry',
        fn ($app): TelemetryManager => new TelemetryManager($app)
    );

    $this->app->alias('prism-telemetry', TelemetryManager::class);
}
```

### Step 8: Update Configuration

Add telemetry section to `config/prism.php`:

```php
'telemetry' => [
    'enabled' => env('PRISM_TELEMETRY_ENABLED', true),
    'default' => env('PRISM_TELEMETRY_DRIVER', 'null'),
    
    'drivers' => [
        'null' => [
            'driver' => 'null',
        ],
        
        'log' => [
            'driver' => 'log',
            'channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'default'),
            'level' => env('PRISM_TELEMETRY_LOG_LEVEL', 'info'),
            'include_attributes' => true,
        ],
    ],
],
```

## Integration Points

### Provider Integration

Update provider handlers to include telemetry:

```php
// In OpenAI Text handler
public function handle(Request $request): Response
{
    return Telemetry::span('prism.provider.request', [
        TelemetryAttribute::ProviderName->value => 'openai',
        TelemetryAttribute::ProviderModel->value => $request->model(),
        TelemetryAttribute::RequestType->value => 'text',
    ], function () use ($request) {
        $response = $this->sendRequest($request);
        
        // Add response metadata to current span
        Telemetry::current()?->setAttributes([
            TelemetryAttribute::RequestTokensInput->value => $response->usage->promptTokens,
            TelemetryAttribute::RequestTokensOutput->value => $response->usage->completionTokens,
        ]);
        
        return $response;
    });
}
```

### Tool Integration

```php
// In Tool::handle method
public function handle(...$args): string
{
    return Telemetry::span('prism.tool.execution', [
        TelemetryAttribute::ToolName->value => $this->name(),
    ], function () use ($args) {
        try {
            $result = call_user_func($this->fn, ...$args);
            
            Telemetry::current()?->setAttribute(
                TelemetryAttribute::ToolSuccess, 
                true
            );
            
            return $result;
        } catch (Throwable $e) {
            Telemetry::current()?->setAttributes([
                TelemetryAttribute::ToolSuccess->value => false,
                TelemetryAttribute::ErrorType->value => $e::class,
                TelemetryAttribute::ErrorMessage->value => $e->getMessage(),
            ]);
            
            throw $e;
        }
    });
}
```

## Testing Strategy

Create tests for each component:

1. **Unit Tests**: Test each driver and span implementation
2. **Integration Tests**: Test manager and facade functionality
3. **Feature Tests**: Test telemetry in actual Prism usage

Example test structure:
```php
// tests/Unit/Telemetry/LogDriverTest.php
it('logs span start and completion', function () {
    Log::fake();
    
    $driver = new LogDriver('test', 'info', true);
    
    $driver->span('test.operation', ['key' => 'value'], function () {
        return 'result';
    });
    
    Log::assertLogged('info', function ($message, $context) {
        return $message === 'Span started' && $context['span.name'] === 'test.operation';
    });
    
    Log::assertLogged('info', function ($message, $context) {
        return $message === 'Span completed' && isset($context['span.duration_ms']);
    });
});
```

## Environment Configuration

Users can configure telemetry via environment variables:

```env
# Enable/disable telemetry
PRISM_TELEMETRY_ENABLED=true

# Choose driver
PRISM_TELEMETRY_DRIVER=log

# Log driver configuration
PRISM_TELEMETRY_LOG_CHANNEL=prism
PRISM_TELEMETRY_LOG_LEVEL=info
```

## Performance Considerations

1. **Null Driver**: Zero overhead when telemetry is disabled
2. **Lazy Evaluation**: Don't compute expensive attributes unless needed
3. **Minimal Allocations**: Use value objects and avoid unnecessary string concatenation
4. **Graceful Degradation**: Never let telemetry break the main functionality
5. **Extensible Design**: Users can extend drivers and spans for custom behavior

## Future Extensions

This architecture supports future enhancements:

1. **OpenTelemetry Driver**: For industry-standard observability
2. **Custom Drivers**: Users can implement their own telemetry backends
3. **Extended Spans**: Custom span implementations with additional functionality
4. **Metrics Collection**: Beyond spans, collect counters and gauges
5. **Sampling**: Implement sampling strategies for high-volume applications
6. **Context Propagation**: Trace requests across service boundaries

## Usage Examples

```php
// Basic usage
Telemetry::span('custom.operation', [
    'user.id' => 123,
    'operation.type' => 'data_processing',
], function () {
    // Your operation here
    return processData();
});

// Manual span control
$span = Telemetry::startSpan('complex.operation');
$span->setAttribute(TelemetryAttribute::RequestType, 'batch');

try {
    // Complex operation
    $result = complexOperation();
    $span->setAttribute('items.processed', count($result));
    return $result;
} catch (Exception $e) {
    $span->setStatus(SpanStatus::Error, $e->getMessage());
    throw $e;
} finally {
    $span->end();
}

// Check if telemetry is enabled
if (Telemetry::enabled()) {
    Telemetry::current()?->addEvent('checkpoint.reached', [
        'checkpoint.name' => 'data_validated',
    ]);
}
```

## Extending the System

Thanks to the extensible design, users can easily customize telemetry behavior:

```php
// Custom driver example
class MetricsDriver extends LogDriver
{
    protected function recordMetric(string $name, float $value): void
    {
        // Send metrics to your monitoring system
        Http::post('https://metrics.example.com/api/metrics', [
            'name' => $name,
            'value' => $value,
            'timestamp' => time(),
        ]);
    }
    
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $startTime = microtime(true);
        
        try {
            $result = parent::span($name, $attributes, $callback);
            $this->recordMetric($name . '.success', 1);
            return $result;
        } catch (Throwable $e) {
            $this->recordMetric($name . '.error', 1);
            throw $e;
        } finally {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordMetric($name . '.duration_ms', $duration);
        }
    }
}

// Custom span example
class EnhancedSpan extends LogSpan
{
    public function setUser(int $userId): self
    {
        return $this->setAttribute('user.id', $userId);
    }
    
    public function setTenant(string $tenantId): self
    {
        return $this->setAttribute('tenant.id', $tenantId);
    }
}
```

This implementation provides a solid foundation for observability in Prism while maintaining Laravel conventions and performance characteristics.
