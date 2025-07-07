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
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a story about AI.')
    ->generate();

echo $response->text;
```

### Structured Output

```php
use Prism\Prism\Facades\Prism;
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
use Prism\Prism\Facades\Prism;
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

### Streaming

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/gpt-4-turbo')
    ->withPrompt('Tell me a long story about AI.')
    ->asStream();

foreach ($stream as $chunk) {
    echo $chunk->text;
}
```

### Streaming with Tools

```php
use Prism\Prism\Facades\Prism;
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

foreach ($stream as $chunk) {
    echo $chunk->text;
    
    // Handle tool calls
    if ($chunk->toolCalls) {
        foreach ($chunk->toolCalls as $toolCall) {
            echo "Tool called: {$toolCall->name}\n";
        }
    }
    
    // Handle tool results
    if ($chunk->toolResults) {
        foreach ($chunk->toolResults as $result) {
            echo "Tool result: {$result->result}\n";
        }
    }
}
```

### Reasoning/Thinking Tokens

Some models (like OpenAI's o1 series) support reasoning tokens that show the model's thought process:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Enums\ChunkType;

$stream = Prism::text()
    ->using(Provider::OpenRouter, 'openai/o1-preview')
    ->withPrompt('Solve this complex math problem: What is the derivative of x^3 + 2x^2 - 5x + 1?')
    ->asStream();

foreach ($stream as $chunk) {
    if ($chunk->chunkType === ChunkType::Thinking) {
        // This is the model's reasoning/thinking process
        echo "Thinking: " . $chunk->text . "\n";
    } else {
        // This is the final answer
        echo $chunk->text;
    }
}
```

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
