# Telemetry

Prism automatically tracks your AI requests and sends telemetry data to observability platforms. Get insights into request performance, token usage, errors, and more.

## What Gets Tracked

- Request duration and token usage
- Provider and model information
- HTTP calls to AI APIs
- Tool/function calls and execution
- Error details and exceptions

## Quick Start

Enable telemetry in your `.env` file:

```env
PRISM_TELEMETRY_ENABLED=true
PRISM_TELEMETRY_DRIVER=log
```

Configure in `config/prism.php`:

```php
'telemetry' => [
    'enabled' => env('PRISM_TELEMETRY_ENABLED', false),
    'driver' => env('PRISM_TELEMETRY_DRIVER', 'log'),
    'drivers' => [
        'log' => [
            'channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'single'),
        ],
    ],
],
```

## Log Driver

Sends telemetry to Laravel's logging system:

```env
PRISM_TELEMETRY_DRIVER=log
PRISM_TELEMETRY_LOG_CHANNEL=single
```

Example telemetry output:

```json
{
  "message": "Telemetry span started",
  "context": {
    "span_id": "abc123def456",
    "span_name": "prism.text.request",
    "attributes": {
      "provider": "openai",
      "model": "gpt-4",
      "prompt_tokens": 15
    }
  }
}
```

## Custom Drivers

Create custom drivers for OpenTelemetry, DataDog, New Relic, or other platforms.

### Interface

```php
interface TelemetryDriver
{
    public function startSpan(string $name, array $attributes = [], ?string $parentId = null): string;
    public function endSpan(string $contextId, array $attributes = []): void;
    public function recordException(string $contextId, \Throwable $exception): void;
}
```

### Registration

Register your driver in a service provider:

```php
namespace App\Providers;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['telemetry-manager']->extend('opentelemetry', function ($app) {
            return new OpenTelemetryDriver;
        });
    }
}
```

Then configure it:

```php
// config/prism.php
'telemetry' => [
    'driver' => 'opentelemetry',
    'drivers' => [
        'opentelemetry' => [
            'service_name' => env('OTEL_SERVICE_NAME', 'my-app'),
        ],
    ],
],
```
