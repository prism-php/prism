# Telemetry

Ever wondered what's happening under the hood when your AI calls take longer than expected? Or wanted to track which providers are performing best for your use case? Prism's telemetry system gives you deep visibility into every AI interaction, helping you optimize performance and debug issues before they impact your users.

## Configuration

Enable telemetry to start tracking all your AI interactions. You'll get detailed performance data without writing a single line of instrumentation code.

First, enable telemetry in your environment:

```bash
PRISM_TELEMETRY_ENABLED=true
```

For better organization and analysis, create a dedicated log channel:

```php
// config/logging.php
'channels' => [
    'prism' => [
        'driver' => 'daily',
        'path' => storage_path('logs/prism.log'),
        'level' => 'info',
        'days' => 14,
    ],
],
```

Then tell Prism to use your new channel:

```bash
PRISM_TELEMETRY_LOG_CHANNEL=prism
```

### Additional Options

Want more control? These optional settings help identify your service in log aggregators and track performance across deployments:

```bash
# Service identification (useful in microservice environments)
PRISM_TELEMETRY_SERVICE_NAME=customer-support-bot
PRISM_TELEMETRY_SERVICE_VERSION=2.1.0
```

You can also configure everything directly in your config file:

```php
// config/prism.php
'telemetry' => [
    'enabled' => env('PRISM_TELEMETRY_ENABLED', false),
    'driver' => \Prism\Prism\Telemetry\LogDriver::class,
    'service_name' => env('PRISM_TELEMETRY_SERVICE_NAME', 'prism'),
    'service_version' => env('PRISM_TELEMETRY_SERVICE_VERSION', '1.0.0'),
    'log_channel' => env('PRISM_TELEMETRY_LOG_CHANNEL', 'default'),
],
```

## Advanced Usage

### Telemetry Drivers

Prism includes several telemetry drivers for different use cases:

#### LogDriver (Default)
The standard driver that writes telemetry data to Laravel's logging system:

```php
// config/prism.php
'telemetry' => [
    'driver' => \Prism\Prism\Telemetry\LogDriver::class,
    // ... other config
],
```

#### ArrayLogDriver
Perfect for testing - captures telemetry data in memory:

```php
// config/prism.php (for testing)
'telemetry' => [
    'driver' => \Prism\Prism\Telemetry\ArrayLogDriver::class,
    // ... other config
],
```

Or manually in tests:

```php
use Prism\Prism\Telemetry\ArrayLogDriver;

// In your test
$telemetry = new ArrayLogDriver(enabled: true);
app()->instance(\Prism\Prism\Contracts\Telemetry::class, $telemetry);

// Run your test
$response = Prism::text()->using('openai', 'gpt-4')->generate('Hello');

// Check telemetry data
$logs = $telemetry->getLogs();
expect($logs)->toHaveCount(2); // start and end events
expect($logs[0]['context']['prism.provider'])->toBe('openai');
```

### Custom Telemetry Drivers

You can create custom telemetry drivers by implementing the `\Prism\Prism\Contracts\Telemetry` interface:

```php
use Prism\Prism\Contracts\Telemetry;

class CustomTelemetryDriver implements Telemetry
{
    public function enabled(): bool
    {
        return true;
    }

    public function start(string $event, array $context = []): void
    {
        // Your custom implementation
    }

    public function end(string $event, array $context = []): void
    {
        // Your custom implementation
    }
}
```

Then configure it in your config:

```php
// config/prism.php
'telemetry' => [
    'driver' => \App\Telemetry\CustomTelemetryDriver::class,
    // ... other config
],
```

## OpenTelemetry Integration

> [!NOTE]
> Looking for enterprise-grade observability? Check out our [OpenTelemetry package](https://github.com/prism-php/opentelemetry) for seamless integration with observability platforms like Jaeger, Zipkin, and New Relic.

The OpenTelemetry integration provides:
- Distributed tracing across your entire application stack
- Integration with popular APM tools
- Advanced span correlation and sampling
- Rich metadata and custom attributes

Perfect for production environments where you need comprehensive observability across multiple services.
