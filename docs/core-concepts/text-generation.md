# Text Generation

Prism provides a powerful interface for generating text using Large Language Models (LLMs). This guide covers everything from basic usage to advanced features like multi-modal interactions and response handling.

## Basic Text Generation

At its simplest, you can generate text with just a few lines of code:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Tell me a short story about a brave knight.')
    ->asText();

echo $response->text;
```

## System Prompts and Context

System prompts help set the behavior and context for the AI. They're particularly useful for maintaining consistent responses or giving the LLM a persona:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->asText();
```

You can also use Laravel views for complex system prompts:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(view('prompts.math-tutor'))
    ->withPrompt('What is calculus?')
    ->asText();
```

You an also pass a View to the `withPrompt` method.

## Multi-Modal Input

Prism supports including images, documents, audio, and video files in your prompts for rich multi-modal analysis:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Video;

// Analyze an image
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'What objects do you see in this image?',
        [Image::fromLocalPath('/path/to/image.jpg')]
    )
    ->asText();

// Process a document
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt(
        'Summarize the key points from this document',
        [Document::fromLocalPath('/path/to/document.pdf')]
    )
    ->asText();

// Analyze audio content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'What is being discussed in this audio?',
        [Audio::fromLocalPath('/path/to/audio.mp3')]
    )
    ->asText();

// Process video content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'Describe what happens in this video',
        [Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
    )
    ->asText();

// Multiple media types in one prompt
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withPrompt(
        'Compare this image with the information in this document',
        [
            Image::fromLocalPath('/path/to/chart.png'),
            Document::fromLocalPath('/path/to/report.pdf')
        ]
    )
    ->asText();
```

## Message Chains and Conversations

For interactive conversations, use message chains to maintain context:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage('What is JSON?'),
        new AssistantMessage('JSON is a lightweight data format...'),
        new UserMessage('Can you show me an example?')
    ])
    ->asText();
```

### Continuing a Conversation

When you continue a conversation Prism already started, pass `$response->messages` back in. Don't rebuild the assistant turns from `$response->text`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt('What is JSON?')
    ->asText();

$followUp = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withMessages([
        ...$response->messages,
        new UserMessage('Can you show me an example?'),
    ])
    ->asText();
```

`$response->messages` holds every turn of the exchange, including tool calls and their results. Each `AssistantMessage` also carries the provider state that newer models expect back exactly as they sent it, in `additionalContent`. That state includes Claude's signed thinking blocks and OpenAI's reasoning items, and Prism replays it in its original order on the next request.

> [!IMPORTANT]
> If you store conversations in your database, store each message's `additionalContent` along with its text. A message rebuilt from text alone still works, but the model loses the reasoning from earlier turns. Conversations that Anthropic binds to their thinking signatures can be rejected if those blocks are edited.

### Message Types

- `SystemMessage`
- `UserMessage`
- `AssistantMessage`
- `ToolResultMessage`

> [!NOTE]
> Some providers, like Anthropic, do not support the `SystemMessage` type. In those cases we convert `SystemMessage` to `UserMessage`.

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

`usingTopK`

Top-K sampling.

The value is passed through to the provider. Top-K sampling considers only the K most likely tokens. For example, a topK of 40 means only the top 40 tokens are considered for sampling. This parameter is supported by providers such as Gemini and Anthropic.

> [!TIP]
> It is recommended to set either temperature or topK, but not both.

`withClientOptions`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/11.x/http-client#main-content). You can use this method to pass any of Guzzles [request options](https://docs.guzzlephp.org/en/stable/request-options.html) e.g. `->withClientOptions(['timeout' => 30])`.

`withClientRetry`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/11.x/http-client#main-content). You can use this method to set [retries](https://laravel.com/docs/11.x/http-client#retries) e.g. `->withClientRetry(3, 100)`.

`usingProviderConfig`

This allows for complete or partial override of the providers configuration. This is great for multi-tenant applications where users supply their own API keys. These values are merged with the original configuration allowing for partial or complete config override.

## Response Handling

The response object provides rich access to the generation results:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Explain quantum computing.')
    ->asText();

// Access the generated text
echo $response->text;

// Check why the generation stopped
echo $response->finishReason->name;

// Get token usage statistics
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Completion tokens: {$response->usage->completionTokens}";

// Access the raw API response data
$rawResponse = $response->raw;

// For multi-step generations, examine each step
foreach ($response->steps as $step) {
    echo "Step text: {$step->text}";
    echo "Step tokens: {$step->usage->completionTokens}";
    // Access raw response for individual steps
    $stepRawResponse = $step->raw;
}

// Access message history
foreach ($response->messages as $message) {
    if ($message instanceof AssistantMessage) {
        echo $message->content;
    }
}
```

### Why Generation Stopped

`$response->finishReason` tells you how the model ended its turn. Check it before you trust `$response->text`:

| Finish reason | What happened |
|---|---|
| `Stop` | The model finished normally |
| `ToolCalls` | The model called tools and `maxSteps` ran out before it finished |
| `Length` | The output hit `maxTokens` (or the model's context window or message limit) |
| `Refusal` | The model declined the request |
| `ContentFilter` | The provider filtered the output |
| `Pause` | The provider paused a long-running turn, such as a server-side tool that's still working. Prism resumes it for you, so you only see `Pause` when `maxSteps` runs out. Raise `maxSteps`, or continue from `$response->messages` |
| `Error` | The provider reported a failure |
| `Other`, `Unknown` | Anything else. The provider's raw value is usually in `additionalContent` |

Refusals come back as a normal response rather than an exception, so you can decide what to do with them:

```php
use Prism\Prism\Enums\FinishReason;

if ($response->finishReason === FinishReason::Refusal) {
    // Anthropic explains why; OpenAI returns the model's refusal message
    $details = $response->additionalContent['stop_details']
        ?? $response->additionalContent['refusal']
        ?? null;

    logger()->warning('Model declined the request', ['details' => $details]);

    return back()->with('error', 'We could not answer that one.');
}
```

> [!TIP]
> A refused prompt stays in the conversation if you continue from `$response->messages`, and the model will usually decline again. Remove the refused exchange before you continue, or set up [server-side fallbacks](/providers/anthropic#fallbacks) on Anthropic.

### Token Usage

`$response->usage` adds up every step of the request. With Anthropic and OpenAI the three input counts don't overlap, so you can price each input bucket separately. `thoughtTokens` is already part of `completionTokens`, so don't charge for it again:

- `promptTokens`: input tokens that were not read from or written to the cache
- `cacheReadInputTokens` and `cacheWriteInputTokens`: cached input
- `completionTokens`: output tokens, including any reasoning
- `thoughtTokens`: the part of `completionTokens` the model spent thinking

Other providers may report these differently, so check the provider's page.

## Handling Completions with Callbacks

Need to perform actions after text generation completes? Pass a callback directly to `asText()` to handle the response without interrupting the return flow. This is perfect for persisting conversations, tracking analytics, or logging AI interactions.

### Basic Example

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withPrompt('Explain Laravel middleware')
    ->asText(function (PendingRequest $request, Response $response) {
        // Save the conversation after generation completes
        ConversationLog::create([
            'content' => $response->text,
            'role' => 'assistant',
            'tool_calls' => $response->toolCalls,
            'usage' => [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ],
        ]);
    });

// Response is still returned normally
echo $response->text;
```

The callback receives the `PendingRequest` and complete `Response` object, giving you access to the full response including text, tool calls, tool results, and usage statistics.

## Error Handling

Remember to handle potential errors in your generations:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
        ->withPrompt('Generate text...')
        ->asText();
} catch (PrismException $e) {
    Log::error('Text generation failed:', ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    Log::error('Generic error:', ['error' => $e->getMessage()]);
}
```
