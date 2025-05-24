# Telemetry

Prism's telemetry system provides detailed insights into your AI application's performance. Track HTTP requests, response times, and provider behavior to optimize costs and troubleshoot issues before they impact users.

## Quick Start

Getting started with telemetry is straightforward. First, enable it in your configuration:

```php
// config/prism.php
'telemetry' => [
    'enabled' => true,
    'driver' => \Prism\Prism\Telemetry\LogDriver::class,
    'service_name' => 'prism',
    'service_version' => '1.0.0',
    'driver_config' => [
        \Prism\Prism\Telemetry\LogDriver::class => [
            'channel' => 'prism_telemetry',
        ],
    ],
],
```

Then create a dedicated log channel for telemetry data:

```php
// config/logging.php
'channels' => [
    'prism_telemetry' => [
        'driver' => 'daily',
        'path' => storage_path('logs/prism-telemetry.log'),
        'level' => 'info',
        'days' => 30,
    ],
],
```

That's it! Prism will now automatically track all HTTP requests to AI providers, giving you visibility into performance patterns and potential bottlenecks.

## Understanding Telemetry Data

When telemetry is enabled, you'll see structured log entries for every provider interaction. Here's what a typical telemetry log looks like:

```json
{
    "message": "Prism span started: openai.http",
    "context": {
        "prism.telemetry.span_name": "openai.http",
        "prism.telemetry.span_id": "01JGXM3K7VQZK9W2Y5D8F6H3N4",
        "prism.telemetry.event": "span.start",
        "prism.telemetry.timestamp": 1705322625.123,
        "http.method": "POST",
        "openai.endpoint": "chat/completions",
        "prism.provider": "openai",
        "prism.model": "gpt-4",
        "prism.request_type": "text"
    }
}
```

And when the request completes:

```json
{
    "message": "Prism span completed: openai.http (1247ms)",
    "context": {
        "prism.telemetry.span_name": "openai.http",
        "prism.telemetry.span_id": "01JGXM3K7VQZK9W2Y5D8F6H3N4",
        "prism.telemetry.event": "span.end",
        "prism.telemetry.duration_ms": 1247,
        "prism.telemetry.status": "success"
    }
}
```

> [!TIP]
> The `span_id` helps you correlate start and end events for the same request, making it easy to track request lifecycles even in high-traffic applications.

## Configuration Options

### Driver Configuration

Configure drivers using the `driver_config` array, which allows driver-specific settings:

```php
'telemetry' => [
    'enabled' => env('PRISM_TELEMETRY_ENABLED', false),
    'driver' => env('PRISM_TELEMETRY_DRIVER', \Prism\Prism\Telemetry\NullDriver::class),
    'service_name' => env('PRISM_TELEMETRY_SERVICE_NAME', 'prism'),
    'service_version' => env('PRISM_TELEMETRY_SERVICE_VERSION', '1.0.0'),
    'driver_config' => [
        \Prism\Prism\Telemetry\LogDriver::class => [
            'channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'default'),
        ],
        \Prism\Prism\Telemetry\OpenTelemetryDriver::class => [
            'endpoint' => env('PRISM_TELEMETRY_ENDPOINT', 'http://localhost:4318/v1/traces'),
        ],
    ],
],
```

### Environment Variables

Control telemetry behavior through environment variables:

```bash
# Enable/disable telemetry
PRISM_TELEMETRY_ENABLED=true

# Driver selection
PRISM_TELEMETRY_DRIVER=\Prism\Prism\Telemetry\LogDriver::class

# Service identification
PRISM_TELEMETRY_SERVICE_NAME=prism
PRISM_TELEMETRY_SERVICE_VERSION=1.0.0

# Log driver configuration
PRISM_TELEMETRY_LOG_CHANNEL=prism_telemetry

# OpenTelemetry driver configuration  
PRISM_TELEMETRY_ENDPOINT=http://localhost:4318/v1/traces
```

> [!IMPORTANT]
> Always use a dedicated log channel for telemetry data. This keeps your application logs clean and makes telemetry analysis much easier.

## Creating Custom Telemetry Drivers

Building your own telemetry driver is straightforward. Simply implement the `Telemetry` contract:

```php
<?php

use Prism\Prism\Contracts\Telemetry;

class CustomTelemetryDriver implements Telemetry
{
    public function enabled(): bool
    {
        return true;
    }

    public function span(string $spanName, array $attributes, callable $callback): mixed
    {
        $startTime = microtime(true);
        
        // Your custom logic here - send to metrics service, database, etc.
        $this->recordSpanStart($spanName, $attributes);
        
        try {
            $result = $callback();
            
            $duration = (microtime(true) - $startTime) * 1000;
            $this->recordSpanSuccess($spanName, $attributes, $duration);
            
            return $result;
        } catch (\Throwable $e) {
            $this->recordSpanError($spanName, $attributes, $e);
            throw $e;
        }
    }

    public function childSpan(string $spanName, array $attributes, callable $callback): mixed
    {
        // Similar implementation for child spans
        return $this->span($spanName, $attributes + ['prism.telemetry.span_type' => 'child'], $callback);
    }
}
```

Register your custom driver in a service provider:

```php
public function register(): void
{
    $this->app->bind(CustomTelemetryDriver::class, function () {
        $driverConfig = config('prism.telemetry.driver_config.' . CustomTelemetryDriver::class, []);
        
        return new CustomTelemetryDriver(
            // Pass driver-specific configuration
            $driverConfig
        );
    });
}
```

Then configure it in your telemetry settings:

```php
'telemetry' => [
    'enabled' => true,
    'driver' => \App\Telemetry\CustomTelemetryDriver::class,
    'driver_config' => [
        \App\Telemetry\CustomTelemetryDriver::class => [
            'custom_setting' => 'value',
            'another_option' => true,
        ],
    ],
],
```

