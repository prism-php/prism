# Text Generation

Prism provides a powerful interface for generating text using Large Language Models (LLMs). This guide covers everything from basic usage to advanced features like multi-modal interactions and response handling.

## Basic Text Generation

At its simplest, you can generate text with just a few lines of code:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withPrompt('Tell me a short story about a brave knight.')
    ->generate();

echo $response->text;
```

## System Prompts and Context

System prompts help set the behavior and context for the AI. They're particularly useful for maintaining consistent responses or giving the LLM a persona:

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->generate();
```

You can also use Laravel views for complex system prompts:

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withSystemPrompt(view('prompts.math-tutor'))
    ->withPrompt('What is calculus?')
    ->generate();
```

You an also pass a View to the `withPrompt` method.

## Message Chains and Conversations

For interactive conversations, use message chains to maintain context:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format...'),
        new UserMessage('Can you show me an example?')
    ])
    ->generate();
```

### Message Types

- `SystemMessage`
- `UserMessage`
- `AssistantMessage`
- `ToolResultMessage`

> [!NOTE]
> Some providers, like Anthropic, do not support the `SystemMessage` type. In those cases we convert `SystemMessage` to `UserMessage`.

## Multi-modal Capabilities (Images)

Prism supports including images in your messages for visual analysis:

```php
use Prism\Prism\ValueObjects\Messages\Support\Image;

// From a local file
$message = new UserMessage(
    "What's in this image?",
    [Image::fromPath('/path/to/image.jpg')]
);

// From a URL
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromUrl('https://example.com/diagram.png')]
);

// From a Base64
$image = base64_encode(file_get_contents('/path/to/image.jpg'));

$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromBase64($image)]
);

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withMessages([$message])
    ->generate();
```

## Generation Parameters

Fine-tune your generations with various parameters:

`withMaxTokens`

Maximum number of tokens to generate.

`usingTemperature`

Temperature setting.

The value is passed through to the provider. The range depends on the provider and model. For most providers, 0 means almost deterministic results, and higher values mean more randomness.

> [!TIP]
> It is recommended to set either temperature or topP, but not both.

`usingTopP`

Nucleus sampling.

The value is passed through to the provider. The range depends on the provider and model. For most providers, nucleus sampling is a number between 0 and 1. E.g. 0.1 would mean that only tokens with the top 10% probability mass are considered.

> [!TIP]
> It is recommended to set either temperature or topP, but not both.

`withClientOptions`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/11.x/http-client#main-content). You can use this method to pass any of Guzzles [request options](https://docs.guzzlephp.org/en/stable/request-options.html) e.g. `->withClientOptions(['timeout' => 30])`.

`withClientRetry`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/11.x/http-client#main-content). You can use this method to set [retries](https://laravel.com/docs/11.x/http-client#retries) e.g. `->withClientRetry(3, 100)`.

## Response Handling

The response object provides rich access to the generation results:

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-sonnet')
    ->withPrompt('Explain quantum computing.')
    ->generate();

// Access the generated text
echo $response->text;

// Check why the generation stopped
echo $response->finishReason->name;

// Get token usage statistics
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Completion tokens: {$response->usage->completionTokens}";

// For multi-step generations, examine each step
foreach ($response->steps as $step) {
    echo "Step text: {$step->text}";
    echo "Step tokens: {$step->usage->completionTokens}";
}

// Access message history
foreach ($response->responseMessages as $message) {
    if ($message instanceof AssistantMessage) {
        echo $message->content;
    }
}
```

### Finish Reasons

```php
case Stop;
case Length;
case ContentFilter;
case ToolCalls;
case Error;
case Other;
case Unknown;
```

## Error Handling

Remember to handle potential errors in your generations:

```php
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-sonnet')
        ->withPrompt('Generate text...')
        ->generate();
} catch (PrismException $e) {
    Log::error('Text generation failed:', ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    Log::error('Generic error:', ['error' => $e->getMessage]);
}
```
