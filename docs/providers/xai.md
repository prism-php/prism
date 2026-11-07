# xAI
## Configuration

```php
'xai' => [
    'api_key' => env('XAI_API_KEY', ''),
    'url' => env('XAI_URL', 'https://api.x.ai/v1'),
],
```

## Provider-specific options

### Extended Thinking/Reasoning

When the selected model returns reasoning content, Prism can expose it as thinking events during streaming.

#### Controlling thinking events

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->withPrompt('Solve this complex equation: 3x² + 5x - 2 = 0')
    ->withProviderOptions(['thinking' => ['enabled' => false]])
    ->asStream();
```

`withProviderOptions(['thinking' => ['enabled' => false]])` suppresses thinking events from the stream. It does not disable the model's internal reasoning or reduce provider billing. The integration does not send this option to xAI. A scalar `thinking => false` does not disable these events.

#### Streaming thinking content

When using streaming, thinking content is yielded as separate events:

```php
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
	->using(Provider::XAI, 'grok-4')
	->withPrompt('Explain quantum entanglement in detail')
	->asStream();

foreach ($stream as $event) {
	if ($event->type() === StreamEventType::ThinkingDelta) {
		echo $event->delta . PHP_EOL; // Outputs: Thinking...
	} elseif ($event->type() === StreamEventType::TextDelta) {
		echo $event->delta;
	}
}
```

### Structured Output

xAI supports structured output through JSON schema validation. The following models support structured output:

> [!NOTE]
> xAI uses an OpenAI-compatible API. For strict schema validation, the root schema should be an `ObjectSchema`.

- `grok-3`
- `grok-4`

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\BooleanSchema;

$schema = new ObjectSchema(
    'weather_report',
    'Weather forecast with recommendations',
    [
        new StringSchema('forecast', 'The weather forecast'),
        new StringSchema('clothing', 'Clothing recommendation'),
        new BooleanSchema('coat_required', 'Whether a coat is needed'),
    ],
    ['forecast', 'clothing', 'coat_required']
);

$response = Prism::structured()
    ->withSchema($schema)
    ->using(Provider::XAI, 'grok-4')
    ->withPrompt('What\'s the weather like in Detroit and should I wear a coat?')
    ->asStructured();

// Access structured data
echo $response->structured['forecast']; // "75° and sunny"
echo $response->structured['coat_required']; // false
```

#### Strict schema mode

Enable strict schema validation for more reliable structured output:

```php
$response = Prism::structured()
    ->withSchema($schema)
    ->using(Provider::XAI, 'grok-4')
    ->withProviderOptions([
        'schema' => ['strict' => true]
    ])
    ->withPrompt('Analyze this data')
    ->asStructured();
```

### Tool Calling

xAI supports function calling with tools. Tools can be used alongside thinking mode for complex problem-solving scenarios.

```php
use Prism\Prism\Facades\Tool;

$tools = [
    Tool::as('calculator')
        ->for('Multiply two numbers')
        ->withNumberParameter('left', 'First factor')
        ->withNumberParameter('right', 'Second factor')
        ->using(fn (float $left, float $right): string => 'Result: '.($left * $right)),
        
    Tool::as('weather')
        ->for('Get current weather information')
        ->withStringParameter('city', 'City name')
        ->using(fn (string $city): string => "Weather in {$city}: 72°F and sunny"),
];

$response = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->withTools($tools)
    ->withMaxSteps(3)
    ->withPrompt('Calculate 15 * 23 and tell me the weather in Detroit')
    ->asText();
```

### Model Parameters

#### Temperature Control

Control the randomness of responses:

```php
$response = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->usingTemperature(0.7)
    ->withPrompt('Write a creative story')
    ->asText();
```

#### Top-P Sampling

Control nucleus sampling:

```php
$response = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->usingTopP(0.9)
    ->withPrompt('Generate diverse responses')
    ->asText();
```

#### Token Limits

Set maximum output tokens:

```php
$response = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->withMaxTokens(1000)
    ->withPrompt('Write a detailed explanation')
    ->asText();
```

## Advanced Examples

### Complex Analysis with Thinking

```php
$response = Prism::text()
    ->using(Provider::XAI, 'grok-4')
    ->withPrompt('
        Analyze the economic implications of implementing a universal basic income program.
        Consider both potential benefits and drawbacks, and provide specific examples.
    ')
    ->asStream();

$analysis = '';
$reasoning = '';

foreach ($response as $event) {
    if ($event->type() === \Prism\Prism\Enums\StreamEventType::ThinkingDelta) {
        $reasoning .= $event->delta;
    } elseif ($event->type() === \Prism\Prism\Enums\StreamEventType::TextDelta) {
        $analysis .= $event->delta;
        echo $event->delta;
    }
}

// Save the reasoning process for later review
file_put_contents('analysis_reasoning.txt', $reasoning);
```

### Structured Data Extraction

```php
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    'financial_analysis',
    'Complete financial analysis result',
    [
        new StringSchema('summary', 'Executive summary'),
        new NumberSchema('total_revenue', 'Total revenue amount'),
        new NumberSchema('profit_margin', 'Profit margin percentage'),
        new ArraySchema('recommendations', 'List of recommendations', 
            new StringSchema('recommendation', 'Individual recommendation')
        ),
        new ObjectSchema('risk_assessment', 'Risk analysis', [
            new StringSchema('level', 'Risk level (low/medium/high)'),
            new NumberSchema('score', 'Risk score from 1-10'),
        ], ['level', 'score']),
    ],
    ['summary', 'total_revenue', 'profit_margin', 'recommendations', 'risk_assessment']
);

$response = Prism::structured()
    ->withSchema($schema)
    ->using(Provider::XAI, 'grok-4')
    ->withPrompt('
        Analyze this financial data: 
        Q1 Revenue: $1.2M, Q1 Costs: $800K
        Q2 Revenue: $1.5M, Q2 Costs: $900K
        Provide a complete analysis with recommendations.
    ')
    ->asStructured();

$analysis = $response->structured;
echo "Revenue: $" . number_format($analysis['total_revenue']);
echo "Risk Level: " . $analysis['risk_assessment']['level'];
```

### Model Validation

Prism does not validate xAI model names locally. An unavailable model or unsupported capability is rejected by the provider API. Handle the resulting request failure:

```php
use Prism\Prism\Exceptions\PrismException;

try {
    $response = Prism::structured()
        ->withSchema($schema)
        ->using(Provider::XAI, 'unsupported-model')
        ->asStructured();
} catch (PrismException $e) {
    // Handle unsupported model error
    echo "Error: " . $e->getMessage();
}
```

## Image generation

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('xai', 'grok-imagine-image-2.0')
    ->withPrompt('A blue notebook on a wooden desk')
    ->withProviderOptions([
        'n' => 1,
        'response_format' => 'b64_json',
        'aspect_ratio' => '1:1',
    ])
    ->generate();

$image = $response->firstImage();
```

Image options include `n`, `response_format`, `aspect_ratio` and `resolution`; additional provider options are also forwarded. Use values supported by the selected [xAI image model](https://docs.x.ai/developers/model-capabilities/imagine). The response exposes `images`, `usage`, `meta` and `raw`. See [Image Generation](/core-concepts/image-generation) for saving results.

## Considerations

### Thinking Content Processing

- Thinking content is automatically filtered to remove repetitive "Thinking..." patterns
- Only meaningful reasoning content is yielded in thinking chunks
- Thinking content appears before regular response content in streams
- Thinking can be disabled if not needed to reduce processing overhead

### API Compatibility

xAI uses an OpenAI-compatible API structure, which means:
- Request/response formats are similar to OpenAI
- Tool calling follows OpenAI's function calling specification
- Structured output uses JSON schema format
- Streaming follows server-sent events (SSE) format

### Token Management

- Thinking tokens count toward your total token usage
- Set appropriate `maxTokens` limits when expecting long thinking sequences
- Monitor usage through the response objects for cost tracking

### Token Usage

xAI reports reasoning tokens next to `completion_tokens` rather than inside it: its own example has `prompt_tokens` 32, `completion_tokens` 9, `reasoning_tokens` 94 and `total_tokens` 135. Prism adds the reasoning back in so `completionTokens` covers every output token, like it does for every other provider (see [Token Usage](/core-concepts/text-generation#token-usage)):

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `prompt_tokens` minus `prompt_tokens_details.cached_tokens` |
| `cacheReadInputTokens` | `prompt_tokens_details.cached_tokens` |
| `cacheWriteInputTokens` | Always `null`, xAI doesn't report cache writes |
| `completionTokens` | `completion_tokens` plus `reasoning_tokens` |
| `thoughtTokens` | `completion_tokens_details.reasoning_tokens`, already part of `completionTokens` |

Prism checks each response's own counters first. When `total_tokens` equals `prompt_tokens` plus `completion_tokens`, the reasoning is already inside `completion_tokens` and isn't added again. Any other total, or no total at all, gets xAI's documented behavior above.

Streams use the last usage xAI sends for each step, whether it comes on every chunk as a running total or in a final usage-only chunk, and the final `StreamEndEvent` adds up every step of a tool loop.
