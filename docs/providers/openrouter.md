# OpenRouter

OpenRouter provides access to multiple AI models through a single API. This provider allows you to use various models from different providers through OpenRouter's routing system.

## Configuration

Add your OpenRouter configuration to `config/prism.php`:

```php
'providers' => [
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
    ],
],
```

## Environment Variables

Set your OpenRouter API key and URL in your `.env` file:

```env
OPENROUTER_API_KEY=your_api_key_here
OPENROUTER_URL=https://openrouter.ai/api/v1
```

## Usage

### Text Generation

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a story about AI.')
    ->generate();

echo $response->text;
```

### Structured Output

> [!NOTE]
> OpenRouter uses OpenAI-compatible structured outputs. For strict schema validation, the root schema should be an `ObjectSchema`.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema('person', 'Person information', [
    new StringSchema('name', 'The person\'s name'),
    new StringSchema('occupation', 'The person\'s occupation'),
]);

$response = Prism::structured()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Generate a person profile for John Doe.')
    ->withSchema($schema)
    ->generate();

echo $response->text;
```

### Tool Calling

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get the current weather for a location')
    ->withStringParameter('location', 'The location to get weather for')
    ->using(function (string $location) {
        return "The weather in {$location} is sunny and 72°F";
    });

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('What is the weather like in New York?')
    ->withTools([$weatherTool])
    ->generate();

echo $response->text;
```

### Multimodal Prompts

OpenRouter keeps the OpenAI content-part schema, so you can mix text and images inside a single user turn.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4o-mini')
    ->withPrompt('Describe the key trends in this diagram.', [
        Image::fromLocalPath('storage/charts/retention.png'),
    ])
    ->generate();

echo $response->text;
```

> [!TIP]
> `Image` value objects are serialized into the `image_url` entries that OpenRouter expects, so you can attach multiple images or pair them with plain text in the same message.

### Streaming

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a long story about AI.')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta;
    }
}
```

> [!NOTE]
> OpenRouter keeps SSE connections alive by emitting comment events such as `: OPENROUTER PROCESSING`. These lines are safe to ignore while parsing the stream.
>
> [!WARNING]
> Mid-stream failures propagate as normal SSE payloads with `error` details and `finish_reason: "error"` while the HTTP status remains 200. Make sure to inspect each chunk for an `error` field so you can surface failures to the caller and stop reading the stream.

### Streaming with Tools

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get the current weather for a location')
    ->withStringParameter('location', 'The location to get weather for')
    ->using(function (string $location) {
        return "The weather in {$location} is sunny and 72°F";
    });

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('What is the weather like in multiple cities?')
    ->withTools([$weatherTool])
    ->asStream();

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::TextDelta => echo $event->delta,
        StreamEventType::ToolCall => echo "Tool called: {$event->toolName}\n",
        StreamEventType::ToolResult => echo "Tool result: " . json_encode($event->result) . "\n",
        default => null,
    };
}
```

### Reasoning/Thinking Tokens

Some models (like OpenAI's o1 series) support reasoning tokens that show the model's thought process:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\StreamEventType;

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/o1-preview')
    ->withPrompt('Solve this complex math problem: What is the derivative of x^3 + 2x^2 - 5x + 1?')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::ThinkingDelta) {
        // This is the model's reasoning/thinking process
        echo "Thinking: " . $event->delta . "\n";
    } elseif ($event->type() === StreamEventType::TextDelta) {
        // This is the final answer
        echo $event->delta;
    }
}
```

#### Reasoning Effort

Control how much reasoning the model performs before generating a response using the `reasoning` parameter. The way this is structured depends on the underlying model you are calling:

```php
$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-5-mini')
    ->withPrompt('Write a PHP function to implement a binary search algorithm with proper error handling')
    ->withProviderOptions([
        'reasoning' => [
            'effort' => 'high',  // Can be "high", "medium", or "low" (OpenAI-style)
            'max_tokens' =>  2000, // Specific token limit (Gemini / Anthropic-style)
            
            // Optional: Default is false. All models support this.
            'exclude' => false, // Set to true to exclude reasoning tokens from response
            // Or enable reasoning with the default parameters:
            'enabled' => true // Default: inferred from `effort` or `max_tokens`
        ]
    ])
    ->asText();
```

### Provider Routing & Advanced Options

Use `withProviderOptions()` to forward OpenRouter-specific controls such as provider preferences, routing, transforms, or additional sampling parameters.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4o')
    ->withPrompt('Draft a concise product changelog entry.')
    ->withProviderOptions([
        'provider' => [
            'require_parameters' => true, // Ensure downstream providers honour every parameter
        ],
        'models' => ['openai/gpt-4o', 'anthropic/claude-3.5-sonnet'],
        'route' => 'fallback',             // Try the next model if the preferred one fails
        'transforms' => ['markdown'],      // See https://openrouter.ai/docs/transforms for available transforms
        'prediction' => [
            'type' => 'content',
            'content' => 'Changelog:\n- ',
        ],
        'user' => 'customer-42',
        'stop' => ["END_OF_CHANGELOG"],
        'seed' => 12345,
        'top_k' => 40,
        'frequency_penalty' => 0.2,
        'presence_penalty' => 0.1,
        'repetition_penalty' => 1.05,
        'min_p' => 0.1,
        'parallel_tool_calls' => false,
        'verbosity' => 'high',
    ])
    ->generate();

echo $response->text;
```

> [!IMPORTANT]
> The values you supply here are passed directly to OpenRouter. Consult the [Parameters reference](https://openrouter.ai/docs/api-reference/parameters) and [Provider Routing guide](https://openrouter.ai/docs/provider-routing) for the full list of supported keys.

## Available Models

OpenRouter supports many models from different providers. Some popular options include:

- `openai/gpt-4-turbo`
- `openai/gpt-3.5-turbo`
- `anthropic/claude-3-5-sonnet`
- `meta-llama/llama-3.1-70b`
- `google/gemini-pro`
- `mistralai/mistral-7b-instruct`

Visit [OpenRouter's models page](https://openrouter.ai/models) for a complete list of available models.

## Features

- ✅ Text Generation
- ✅ Structured Output
- ✅ Tool Calling
- ✅ Multiple Model Support
- ✅ Provider Routing
- ✅ Streaming
- ✅ Reasoning/Thinking Tokens (for compatible models)
- ❌ Embeddings (not yet implemented)
- ❌ Image Generation (not yet implemented)

## API Reference

For detailed API documentation, visit [OpenRouter's API documentation](https://openrouter.ai/docs/api-reference/chat-completion).

## Error Handling

The OpenRouter provider includes standard error handling for common issues:

- Rate limiting
- Request too large
- Provider overload
- Invalid API key

Errors are automatically mapped to appropriate Prism exceptions for consistent error handling across all providers. 
