# Telemetry

Prism includes a built-in telemetry system that helps you monitor and debug your AI interactions. Whether you're troubleshooting slow responses, tracking usage patterns, or building observability into your applications, telemetry gives you the insights you need.

## Getting Started

Telemetry is enabled by default but uses a "null" driver that doesn't actually record anything. To start collecting telemetry data, you'll want to configure a driver in your environment:

```bash
# Enable telemetry with the log driver
PRISM_TELEMETRY_ENABLED=true
PRISM_TELEMETRY_DRIVER=log
```

That's it! Prism will now automatically track all your AI requests, tool calls, and other operations.

## How It Works

The telemetry system creates "spans" - records that track the duration and metadata of operations. Every time you make a text generation request, call a tool, or perform an embedding operation, Prism automatically creates a span with relevant details.

Here's what gets tracked automatically:

- **Text Generation**: Provider, model, token usage, and timing
- **Structured Output**: Schema validation, response format, and performance
- **Embeddings**: Input processing, vector generation, and metadata
- **Tool Calls**: Tool name, execution status, and error details

## Configuration

Configure telemetry in your `config/prism.php` file:

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

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PRISM_TELEMETRY_ENABLED` | `true` | Enable or disable telemetry collection |
| `PRISM_TELEMETRY_DRIVER` | `null` | Which telemetry driver to use |
| `PRISM_TELEMETRY_LOG_CHANNEL` | `default` | Laravel log channel for the log driver |
| `PRISM_TELEMETRY_LOG_LEVEL` | `info` | Log level for telemetry events |

## Available Drivers

### Null Driver

The null driver is perfect for production environments where you want zero telemetry overhead. It implements all telemetry methods as no-ops, so there's virtually no performance impact.

```php
'drivers' => [
    'null' => [
        'driver' => 'null',
    ],
],
```

### Log Driver

The log driver writes telemetry data to your Laravel logs. It's great for development and debugging, giving you detailed insights into what's happening under the hood.

```php
'drivers' => [
    'log' => [
        'driver' => 'log',
        'channel' => 'telemetry', // Custom log channel
        'level' => 'debug',       // Log level
        'include_attributes' => true, // Include span attributes in logs
    ],
],
```

When a span completes, you'll see log entries like this:

```
[2024-01-15 10:30:45] local.INFO: Span started: prism.text {"span_id":"550e8400-e29b-41d4-a716-446655440000","operation":"prism.text","attributes":{"prism.provider.name":"App\\Providers\\OpenAI\\OpenAI","prism.provider.model":"gpt-4","prism.request.type":"text"}}

[2024-01-15 10:30:47] local.INFO: Span ended: prism.text {"span_id":"550e8400-e29b-41d4-a716-446655440000","operation":"prism.text","duration_ms":1876,"status":"ok"}
```

## Manual Telemetry

While Prism automatically tracks most operations, you can also create custom spans for your own code:

```php
use EchoLabs\Prism\Facades\Telemetry;

// Simple span with callback
$result = Telemetry::span('custom.operation', [
    'user_id' => auth()->id(),
    'operation_type' => 'data_processing',
], function () {
    // Your operation logic here
    return processUserData();
});

// Manual span control for more complex scenarios
$span = Telemetry::startSpan('complex.operation');
$span->setAttribute('batch_size', count($items));

try {
    foreach ($items as $item) {
        processItem($item);
        $span->addEvent('item.processed', ['item_id' => $item->id]);
    }
    
    $span->setStatus(SpanStatus::Ok);
    return $results;
} catch (Exception $e) {
    $span->setStatus(SpanStatus::Error);
    $span->setAttribute('error.message', $e->getMessage());
    throw $e;
} finally {
    $span->end();
}
```

## Telemetry Attributes

Prism includes predefined attributes for common use cases:

```php
use EchoLabs\Prism\Telemetry\ValueObjects\TelemetryAttribute;

// Provider information
TelemetryAttribute::ProviderName->value     // 'prism.provider.name'
TelemetryAttribute::ProviderModel->value    // 'prism.provider.model'

// Request details
TelemetryAttribute::RequestType->value      // 'prism.request.type'
TelemetryAttribute::RequestTokensInput->value   // 'prism.request.tokens.input'
TelemetryAttribute::RequestTokensOutput->value  // 'prism.request.tokens.output'

// Tool execution
TelemetryAttribute::ToolName->value         // 'prism.tool.name'
TelemetryAttribute::ToolSuccess->value      // 'prism.tool.success'

// Error tracking
TelemetryAttribute::ErrorType->value        // 'prism.error.type'
TelemetryAttribute::ErrorMessage->value     // 'prism.error.message'
```

## Span Context

The telemetry system maintains a "current span" context that you can access from anywhere in your application:

```php
// Get the current active span
$currentSpan = Telemetry::current();

if ($currentSpan) {
    $currentSpan->setAttribute('cache.hit', true);
    $currentSpan->addEvent('cache.lookup.completed');
}

// Execute code within a specific span context
Telemetry::withCurrentSpan($customSpan, function () {
    // Any telemetry calls here will use $customSpan as the current span
    performOperation();
});
```

## Performance Considerations

Telemetry is designed to have minimal performance impact:

- **Null Driver**: Zero overhead - all operations are no-ops
- **Log Driver**: Minimal overhead - logs are written asynchronously
- **Disabled State**: When `PRISM_TELEMETRY_ENABLED=false`, the manager short-circuits to the null driver

> [!TIP]
> Use the null driver in production for zero overhead, and switch to the log driver when you need to debug issues.

## Custom Drivers

You can create custom telemetry drivers by implementing the `TelemetryDriver` contract:

```php
use EchoLabs\Prism\Telemetry\Contracts\TelemetryDriver;
use EchoLabs\Prism\Telemetry\Contracts\Span;

class CustomTelemetryDriver implements TelemetryDriver
{
    public function startSpan(string $name, array $attributes = [], ?float $startTime = null): Span
    {
        // Create and return your custom span implementation
        return new CustomSpan($name, $attributes, $startTime);
    }
}
```

Then register it in your telemetry configuration:

```php
'drivers' => [
    'custom' => [
        'driver' => 'custom',
        // Additional configuration options
    ],
],
```

## Troubleshooting

### Telemetry Not Working

1. **Check if telemetry is enabled**: Verify `PRISM_TELEMETRY_ENABLED=true`
2. **Verify the driver**: Make sure `PRISM_TELEMETRY_DRIVER` points to a valid driver
3. **Log driver issues**: Check your Laravel log configuration and permissions

### Performance Issues

If you're experiencing performance issues with telemetry:

1. **Switch to null driver**: Set `PRISM_TELEMETRY_DRIVER=null` for zero overhead
2. **Reduce log level**: Use `PRISM_TELEMETRY_LOG_LEVEL=error` to log only errors
3. **Disable attributes**: Set `include_attributes` to `false` in your log driver config

### Missing Spans

If you're not seeing expected telemetry data:

1. **Check span lifecycle**: Ensure spans are properly ended (use callback style when possible)
2. **Verify context**: Make sure operations are running within the expected span context
3. **Driver configuration**: Confirm your driver is configured correctly

The telemetry system gives you powerful insights into your AI application's behavior. Start with the log driver during development, then optimize for production with the null driver or a custom solution that fits your observability stack.