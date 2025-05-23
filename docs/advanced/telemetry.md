# OpenTelemetry Integration

Prism includes built-in OpenTelemetry integration to provide comprehensive observability into your LLM operations. This allows you to trace the entire flow of Prism requests, including text generation, structured output, embeddings, tool calls, and streaming responses.

## Overview

The telemetry integration captures detailed spans for:

- **Text Generation**: Complete request lifecycle including provider calls
- **Structured Output**: Schema validation and generation flows  
- **Embeddings**: Input processing and vector generation
- **Tool Calls**: Individual tool execution with arguments and results
- **Streaming**: Chunk-by-chunk response processing with metadata

## Configuration

### Enable Telemetry

Add the following environment variables to your `.env` file:

```env
# Enable telemetry
PRISM_TELEMETRY_ENABLED=true

# Service identification
PRISM_TELEMETRY_SERVICE_NAME=my-laravel-app
PRISM_TELEMETRY_SERVICE_VERSION=1.0.0

# OpenTelemetry endpoint (Jaeger, Zipkin, etc.)
PRISM_TELEMETRY_ENDPOINT=http://localhost:4318/v1/traces
```

### Configuration Options

| Variable | Default | Description |
|----------|---------|-------------|
| `PRISM_TELEMETRY_ENABLED` | `false` | Enable/disable telemetry collection |
| `PRISM_TELEMETRY_SERVICE_NAME` | `prism` | Service name in traces |
| `PRISM_TELEMETRY_SERVICE_VERSION` | `1.0.0` | Service version |
| `PRISM_TELEMETRY_ENDPOINT` | `http://localhost:4318/v1/traces` | OTLP HTTP endpoint |

## Setting Up Jaeger

Jaeger is a popular open-source tracing platform. Here's how to set it up locally:

### Docker Compose Setup

Create a `docker-compose.yml` file in your project root:

```yaml
version: '3.8'
services:
  # Jaeger
  jaeger-all-in-one:
    image: jaegertracing/all-in-one:latest
    ports:
      - "16686:16686"   # Jaeger UI
      - "14268:14268"   # Jaeger HTTP receiver
      - "4317:4317"     # OTLP gRPC receiver
      - "4318:4318"     # OTLP HTTP receiver
    environment:
      - COLLECTOR_OTLP_ENABLED=true
    networks:
      - tracing

  # Your Laravel application
  laravel-app:
    build: .
    ports:
      - "8000:8000"
    environment:
      - PRISM_TELEMETRY_ENABLED=true
      - PRISM_TELEMETRY_SERVICE_NAME=my-laravel-app
      - PRISM_TELEMETRY_ENDPOINT=http://jaeger-all-in-one:4318/v1/traces
    networks:
      - tracing
    depends_on:
      - jaeger-all-in-one

networks:
  tracing:
    driver: bridge
```

### Start the Services

```bash
docker-compose up -d
```

Access the Jaeger UI at `http://localhost:16686`

## Alternative Tracing Backends

### Zipkin

```env
PRISM_TELEMETRY_ENDPOINT=http://localhost:9411/api/v2/spans
```

### Grafana Tempo

```env
PRISM_TELEMETRY_ENDPOINT=http://localhost:3200/v1/traces
```

### Cloud Providers

#### AWS X-Ray
```env
PRISM_TELEMETRY_ENDPOINT=https://xray.us-east-1.amazonaws.com/v1/traces
```

#### Google Cloud Trace
```env
PRISM_TELEMETRY_ENDPOINT=https://cloudtrace.googleapis.com/v1/projects/PROJECT_ID/traces
```

## Span Structure

Prism creates a hierarchical span structure for complete request tracing:

```
prism.text.generate (root span)
├── prism.tool.call.get_weather (child span)
├── prism.tool.call.format_response (child span)
└── prism.tool.call.send_notification (child span)

prism.structured.generate (root span)
├── prism.tool.call.search_database (child span)
└── prism.tool.call.validate_output (child span)

prism.embeddings.generate (root span)
└── [no child spans - simple operation]

prism.text.stream (root span)
├── prism.tool.call.get_context (child span)
└── prism.tool.call.process_stream (child span)
```

## Span Attributes

Each span includes relevant attributes for filtering and analysis:

### Common Attributes
- `prism.provider` - Provider class name (e.g., `OpenAI`, `Anthropic`)
- `prism.model` - Model identifier (e.g., `gpt-4`, `claude-3-sonnet`)
- `prism.request_type` - Type of request (`text`, `structured`, `embeddings`, `stream`)

### Text Generation
- `prism.temperature` - Generation temperature
- `prism.max_tokens` - Maximum tokens limit
- `prism.finish_reason` - How generation completed

### Structured Output
- `prism.schema_type` - Schema class or type name
- `prism.structured_mode` - Mode used (`json`, `tool`)

### Embeddings
- `prism.input_count` - Number of inputs processed
- `prism.embedding_dimensions` - Vector dimensions

### Tool Calls
- `prism.tool.name` - Tool function name
- `prism.tool.call_id` - Unique call identifier
- `prism.tool.arg_count` - Number of arguments

### Streaming
- `prism.stream.chunk_count` - Total chunks received

## Performance Impact

When `PRISM_TELEMETRY_ENABLED=false`, the telemetry system has **zero overhead**:

- No span creation
- No attribute setting  
- No network calls
- Minimal method call overhead

When enabled, the impact is minimal:
- ~1-2ms per span creation
- Asynchronous export to prevent blocking
- Automatic batching for efficiency

## Debugging with Traces

### Finding Slow Requests

Filter spans by duration in Jaeger to identify performance bottlenecks:

```
operation:"prism.text.generate" duration:>5s
```

### Tracking Tool Usage

Monitor which tools are called most frequently:

```
operation:"prism.tool.call.*"
```

### Provider Performance Comparison

Compare response times across different providers:

```
prism.provider:"OpenAI" vs prism.provider:"Anthropic"
```

### Error Analysis

Find failed requests and their causes:

```
error:true AND prism.request_type:"text"
```

## Advanced Usage

### Custom Span Attributes

You can add custom attributes to spans using the `HasTelemetry` trait:

```php
use Prism\Prism\Concerns\HasTelemetry;

class CustomService
{
    use HasTelemetry;

    public function processData($data)
    {
        return $this->trace('custom.process', function ($span) use ($data) {
            $this->addSpanAttributes($span, [
                'custom.data_size' => strlen($data),
                'custom.user_id' => auth()->id(),
            ]);
            
            // Your processing logic
            return $this->processDataInternal($data);
        });
    }
}
```

### Distributed Tracing

Prism automatically participates in distributed traces when called within an existing trace context. This allows you to see Prism operations as part of larger request flows.

## Troubleshooting

### Traces Not Appearing

1. **Check Configuration**
   ```bash
   php artisan config:cache
   php artisan config:clear
   ```

2. **Verify Endpoint Connectivity**
   ```bash
   curl -X POST http://localhost:4318/v1/traces \
     -H "Content-Type: application/json" \
     -d '{}'
   ```

3. **Enable Debug Logging**
   ```env
   LOG_LEVEL=debug
   ```

### High Memory Usage

If you experience memory issues:

1. **Reduce Span Attributes**
   - Avoid large text content in attributes
   - Use sampling for high-volume applications

2. **Configure Batching**
   ```php
   // In a custom service provider
   OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor::builder()
       ->setMaxQueueSize(512)
       ->setExportTimeoutMillis(30000)
       ->build();
   ```

### Network Timeouts

For unreliable networks, configure timeouts:

```env
# Custom timeout for trace exports
PRISM_TELEMETRY_TIMEOUT=10000
```

## Production Considerations

### Sampling

For high-volume production applications, implement sampling:

```php
// In AppServiceProvider
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;

public function register()
{
    if (app()->environment('production')) {
        // Sample 10% of traces
        $sampler = new TraceIdRatioBasedSampler(0.1);
    }
}
```

### Security

- **Sanitize Sensitive Data**: Avoid including API keys, passwords, or PII in span attributes
- **Network Security**: Use TLS for trace exports in production
- **Access Control**: Restrict access to tracing UI and data

### Resource Limits

- **Span Attribute Limits**: Keep attribute values under 1KB
- **Span Count**: Monitor span creation rate
- **Export Frequency**: Balance between latency and resource usage

## Integration Examples

### Basic Text Generation

```php
use Prism\Prism\Prism;

$response = Prism::text()
    ->using('openai', 'gpt-4')
    ->withPrompt('Explain quantum computing')
    ->asText();

// Creates spans:
// - prism.text.generate
//   - prism.provider.openai
```

### Tool Usage with Tracing

```php
$response = Prism::text()
    ->using('anthropic', 'claude-3-sonnet')
    ->withPrompt('What is the weather in Paris?')
    ->withTools([new WeatherTool()])
    ->asText();

// Creates spans:
// - prism.text.generate
//   - prism.provider.anthropic
//   - prism.tool.call.get_weather
```

### Streaming with Metrics

```php
foreach (Prism::text()->withPrompt('Tell a story')->asStream() as $chunk) {
    echo $chunk->text;
}

// Creates spans:
// - prism.text.stream
//   - prism.provider.openai
//   - Attributes: prism.stream.chunk_count=42
```

This comprehensive telemetry integration provides full visibility into your Prism operations, enabling better debugging, performance optimization, and operational insights.