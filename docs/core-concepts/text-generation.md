# Text Generation

Generate text with a configured provider and model. Requests can include system prompts, conversation history and supported media.

## Basic Text Generation

Select a provider and model, set a prompt, and call `asText()`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
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
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withSystemPrompt('You are an expert mathematician who explains concepts simply.')
    ->withPrompt('Explain the Pythagorean theorem.')
    ->asText();
```

You can also use Laravel views for complex system prompts:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withSystemPrompt(view('prompts.math-tutor'))
    ->withPrompt('What is calculus?')
    ->asText();
```

You can also pass a View to the `withPrompt` method.

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
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        'What objects do you see in this image?',
        [Image::fromLocalPath('/path/to/image.jpg')]
    )
    ->asText();

// Process a document
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt(
        'Summarize the key points from this document',
        [Document::fromLocalPath('/path/to/document.pdf')]
    )
    ->asText();

// Analyze audio content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'What is being discussed in this audio?',
        [Audio::fromLocalPath('/path/to/audio.mp3')]
    )
    ->asText();

// Process video content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Describe what happens in this video',
        [Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
    )
    ->asText();

// Multiple media types in one prompt
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Compare this image with the information in this document',
        [
            Image::fromLocalPath('/path/to/chart.png'),
            Document::fromLocalPath('/path/to/report.pdf')
        ]
    )
    ->asText();

// Multi-image analysis with Qwen VL models
$response = Prism::text()
    ->using(Provider::Qwen, 'qwen-vl-max')
    ->withPrompt(
        'What are these?',
        [
            Image::fromUrl('https://example.com/dog.jpeg'),
            Image::fromUrl('https://example.com/tiger.png'),
            Image::fromUrl('https://example.com/rabbit.png'),
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
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
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

Supply instructions through `withSystemPrompt()` or `withSystemPrompts()`. How a provider maps system messages placed inside the conversation differs; see its provider guide before using that form.

### Threads

Implement `Thread` to supply conversation history from your application's storage:

```php
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread;

class Conversation extends Model implements Thread
{
    /** @return iterable<int, Message> */
    public function messages(): iterable
    {
        return $this->turns->map(fn (Turn $turn): Message => $turn->toPrismMessage());
    }
}
```

Pass the thread with `withThread()` and the new user message with `withPrompt()`. Unlike `withMessages()`, these methods can be used together:

```php
$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withThread($conversation)
    ->withPrompt('Can you show me an example?')
    ->asText();
```

Prism reads from a thread but does not write to it. Persist `$response->messages` in your application to record the exchange, including tool calls and results.

```php
$conversation->record($response->messages);
```

`messages()` returns an `iterable`, allowing incremental reads from storage. Prism still materializes the complete message list for the provider request. Limit the history you supply to manage memory use and input tokens.

> [!WARNING]
> A thread is replayed as context, and `Message` includes `SystemMessage` — so anything that can write to your conversation store can put instructions in front of the model. Treat stored history as untrusted input: restrict who can write it, and don't persist a `SystemMessage` you didn't author.

> [!NOTE]
> Prism provides the `Thread` interface; your application supplies storage. `withThread()` works with both `Prism::text()` and `Prism::structured()`.

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

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/13.x/http-client#main-content). You can use this method to pass any of Guzzles [request options](https://docs.guzzlephp.org/en/stable/request-options.html) e.g. `->withClientOptions(['timeout' => 30])`.

`withClientRetry`

Under the hood we use Laravel's [HTTP client](https://laravel.com/docs/13.x/http-client#main-content). You can use this method to set [retries](https://laravel.com/docs/13.x/http-client#retries) e.g. `->withClientRetry(3, 100)`.

`usingProviderConfig`

This allows for complete or partial override of the providers configuration. This is great for multi-tenant applications where users supply their own API keys. These values are merged with the original configuration allowing for partial or complete config override.

## Request configuration

`using($provider, $model, $providerConfig)` accepts a third argument that overrides provider configuration for that request. Use it for application-controlled credentials or endpoints; keep these values out of model-generated options and browser payloads.

| Method | Behavior |
|---|---|
| `withSystemPrompt($message)` | Appends one string, view or `SystemMessage` |
| `withSystemPrompts($messages)` | Replaces the complete system-prompt list with `SystemMessage` objects |
| `withProviderOptions($options)` | Replaces the request option array; keys and support depend on the provider |
| `withReasoning(false)` | Requests the provider-specific reasoning behavior described in its guide |
| `whenProvider($provider, $callback)` | Calls the callback only when the selected provider key matches; return the builder |
| `withProviderTools($tools)` | Supplies `ProviderTool` objects for provider-side tools |
| `withToolChoice($choice)` | Selects a `ToolChoice` value, a tool name or a Tool object, when supported |
| `withTelemetryMetadata($userId, $sessionId)` | Adds optional opaque identifiers to telemetry; it does not enable content capture |

For example, with an application-supplied tenant credential:

```php
use Illuminate\Http\Client\ConnectionException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

// $tenantApiKey comes from your authenticated application's configuration.
$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5', ['api_key' => $tenantApiKey])
    ->withSystemPrompts([new SystemMessage('Explain concepts concisely.')])
    ->whenProvider('anthropic', fn ($request) => $request
        ->withProviderOptions(['effort' => 'medium']))
    ->withClientOptions(['timeout' => 30])
    ->withClientRetry(
        times: [200, 500, 1000],
        when: fn ($exception): bool => $exception instanceof ConnectionException,
        throw: true,
    )
    ->withTelemetryMetadata(userId: 'customer-123', sessionId: 'chat-456')
    ->withPrompt('What is a database index?')
    ->asText();
```

`withClientRetry(array|int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true)` forwards these arguments to Laravel's HTTP client. An array specifies delays between attempts; an integer specifies the attempt count with the separate delay. Retries can repeat a billable provider request. Check provider-specific exceptions such as [Azure's native v1 streaming path](/providers/azure#streaming) before relying on HTTP-client options or fakes.

Mark reusable messages with `withCacheHint()` when using supported caching integrations. A hint describes stability; creating a billable Gemini or Vertex cached-content resource also requires explicit `cacheStablePrefix` opt-in. See the [provider caching guide](/providers/gemini#caching).

Do not combine a nonempty `withPrompt()` with explicit `withMessages()`; request creation throws `PrismException::promptOrMessages()`. Use `withThread()` for stored history plus a new prompt, or append the user turn to the explicit message list.

## Response Handling

Read text, usage and metadata from the response:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
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

`$response->meta` contains the provider response ID, model, reported rate-limit buckets and optional service tier. On a text response, `toolCalls` and `toolResults` describe the final step; inspect each item in `$response->steps` for earlier tool activity. A final text answer can therefore have an empty top-level `toolResults` array after tools ran on a preceding step. Structured responses aggregate their tool calls/results across steps.

`$response->additionalContent` contains provider-specific state such as reasoning, citations or billing evidence. Preserve provider state with message history when continuing a conversation. Raw payloads can be `null` when a provider does not populate them; treat them as sensitive when storing or logging.

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

`$response->usage` adds up every step of the request, and every provider fills it the same way:

| Field | What it counts |
|---|---|
| `promptTokens` | Input tokens that were neither read from nor written to a cache. Includes input a provider adds for its built-in tools |
| `cacheReadInputTokens` | Input tokens read from a cache |
| `cacheWriteInputTokens` | Input tokens written to a cache |
| `cacheWrite5mInputTokens` | The part of `cacheWriteInputTokens` written with a 5-minute lifetime, when reported |
| `cacheWrite1hInputTokens` | The part of `cacheWriteInputTokens` written with a 1-hour lifetime, when reported |
| `completionTokens` | Every output token, reasoning included |
| `thoughtTokens` | The part of `completionTokens` the model spent reasoning |

`promptTokens`, `cacheReadInputTokens` and `cacheWriteInputTokens` never overlap, so their sum is the request's total input. `thoughtTokens` is already inside `completionTokens`, so don't add the two together or price reasoning twice.

The optional fields (`cacheReadInputTokens`, `cacheWriteInputTokens` and `thoughtTokens`) are `null` when the provider doesn't report them and `0` when it reports zero. Only some providers report cache writes, and some don't break out reasoning, so `null` there means "not reported", not "none". `promptTokens` and `completionTokens` are always integers, so a response that carries no usage at all shows `0` for both.

The cache-write lifetime counters are independently nullable. They are breakdowns of `cacheWriteInputTokens`, so don't add them to that total. When both buckets are known, their sum equals the combined write count. A nonzero write without a lifetime report leaves that aggregate bucket `null`. Steps whose write total is `0` or `null` contribute zero to the split; if every step's total is unreported, the aggregate stays `null`. Don't infer every write's lifetime from your request: provider-managed tool results can create additional cache writes.

Anthropic prices 5-minute writes at **1.25 times** the model's base input rate and 1-hour writes at **2 times** that rate. Price the buckets separately, and don't charge `cacheWriteInputTokens` again. For base input rate `$inputRate` in USD per million tokens, the write portion is:

```php
$usage = $response->usage;
$writeCost = $usage->cacheWrite5mInputTokens !== null && $usage->cacheWrite1hInputTokens !== null
    ? ($usage->cacheWrite5mInputTokens * 1.25 + $usage->cacheWrite1hInputTokens * 2) * $inputRate / 1_000_000
    : null; // The provider did not report enough information to price the split.
```

Apply model, batch, context-length or regional modifiers separately. Check Anthropic's [current caching prices](https://platform.claude.com/docs/en/build-with-claude/prompt-caching#pricing) for the rates applicable to your request.

> [!NOTE]
> `Usage` is normalized token accounting, not a full bill. Charges outside these counters aren't in it: per-request and search fees, Perplexity's reasoning and citation tokens, and Anthropic fallback attempts on other models (see `usage_iterations` on the [Anthropic page](/providers/anthropic#fallbacks)). Ollama only splits out cached input on servers that report it (0.33.3 and later). Check the provider's page for these details.

`$response->usage->cost` is an optional provider-reported cost in USD. `null` means no cost was reported; `0.0` is a reported zero. Read the provider guide for which charges the figure includes. Cache and thought fields are token breakdowns and should not be added to counters that already include them.

## Handling Completions with Callbacks

Pass a callback to `asText()` to process the completed response before it is returned, for example to persist messages or record usage.

### Basic Example

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
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
use Illuminate\Support\Facades\Log;
use Throwable;

try {
    $response = Prism::text()
        ->using(Provider::Anthropic, 'claude-sonnet-5-5')
        ->withPrompt('Generate text...')
        ->asText();
} catch (PrismException $e) {
    Log::error('Text generation failed', ['exception_type' => $e::class]);
} catch (Throwable $e) {
    Log::error('Generation failed', ['exception_type' => $e::class]);
}
```
