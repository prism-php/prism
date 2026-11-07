# OpenAI
## Configuration

```php
'openai' => [
    'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
    'api_key' => env('OPENAI_API_KEY', ''),
    'organization' => env('OPENAI_ORGANIZATION', null),
    'project' => env('OPENAI_PROJECT', null),
    'api_format' => env('OPENAI_API_FORMAT', 'responses'),
]
```

### OpenAI-compatible endpoints

`url` is not fixed to OpenAI. Point it at anything that speaks the OpenAI API
and the provider works unchanged — a self-hosted runtime such as vLLM, LM Studio
or llama.cpp, an Azure OpenAI deployment, or a gateway or proxy your team runs in
front of several model vendors to centralise keys, quotas, and audit logging.

Set the base URL and the key that endpoint expects:

```env
OPENAI_URL=https://your-endpoint.example.com/v1
OPENAI_API_KEY=your-endpoint-key
```

Then use whichever model name that endpoint publishes. Nothing else in your
application changes:

```php
$response = Prism::text()
    ->using(Provider::OpenAI, 'the-model-name-your-endpoint-exposes')
    ->withPrompt('Summarise the release risk for this change.')
    ->asText();
```

Two things to expect when you do this:

- **Feature parity varies.** "OpenAI-compatible" usually means chat completions.
  Structured outputs, tool calling, streaming, embeddings, audio, and the
  Responses API are each supported only if that endpoint implements them.
  Prism sends the same request either way, so an unsupported feature surfaces as
  an error from the endpoint rather than from Prism.
- **Structured mode depends on the model name.** Legacy `gpt-3.5-turbo`, `gpt-4`
  and `gpt-4-*` names, plus `gpt-4o-2024-05-13`, use JSON mode. The `o1-mini`
  and `o1-preview` names and their `2024-09-12` snapshots are rejected. Other
  names, including custom model names, use JSON Schema structured output by
  default. Your endpoint must support the selected mode.

If the endpoint you are targeting already has a dedicated Prism provider —
Groq, Mistral, DeepSeek, xAI, OpenRouter, Requesty, Qwen, Ollama, Azure — prefer
that provider. Each one maps the quirks its API actually has, which the generic
OpenAI provider will not do for you.

## Chat Completions refusals

With `api_format` set to `chat_completions`, text, structured and streaming
requests throw `Prism\Prism\Exceptions\PrismRefusalException` when the model
returns a non-empty refusal. It extends `PrismException`; `code()` returns
`response_refused` and `refusal()` returns the provider's refusal text. Treat that
text as potentially sensitive. It is excluded from the exception message and
public `responseBody`.

Streaming collects refusal fragments and throws at the finish event, before
text completion, tool execution or successful stream completion. Previously
yielded text remains provisional. If the stream closes without a finish event,
an observed refusal still throws. Empty content without a refusal remains an
empty answer; structured output still requires valid JSON. A non-empty refusal
takes precedence even when the response also contains text.

## Provider-specific options
### Embedding encoding

Use `withProviderOptions(['encoding_format' => 'base64'])` on an embeddings
request to request base64 output. Prism decodes each string as little-endian
float32 and returns the usual numeric embedding vector; `raw` retains the
provider payload. Numeric array responses remain supported.

Malformed or noncanonical base64, empty encodings, incomplete float32 values
and non-finite numbers throw `PrismException`. Invalid payloads are not included
in the exception message.

### Strict Tool Schemas

Prism supports OpenAI's [function calling with Structured Outputs](https://platform.openai.com/docs/guides/function-calling#function-calling-with-structured-outputs) via provider-specific meta.

```php
Tool::as('search') // [!code focus]
    ->for('Searching the web')
    ->withStringParameter('query', 'the detailed search query')
    ->using(fn (): string => '[Search results]')
    ->withProviderOptions([ // [!code focus]
      'strict' => true, // [!code focus]
    ]); // [!code focus]
```

### Strict Structured Output Schemas

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'schema' => [ // [!code focus]
            'strict' => true // [!code focus]
        ] // [!code focus]
    ]); // [!code focus]
```

> [!WARNING]
> **All Fields Must Be Required**: When using structured outputs with OpenAI (especially in strict mode), you must include ALL fields in the `requiredFields` array. Fields that should be optional must be marked with `nullable: true` instead. This is an OpenAI API requirement and applies to all structured output requests.
>
> ```php
> new ObjectSchema(
>     name: 'user',
>     properties: [
>         new StringSchema('email', 'Email address'),
>         new StringSchema('bio', 'Optional bio', nullable: true),
>     ],
>     requiredFields: ['email', 'bio'] // ✅ All fields listed
> );
> ```
>
> For more details on required vs nullable fields, see [Schemas - Required vs Nullable Fields](/core-concepts/schemas#required-vs-nullable-fields).

### Structured Output Mode

Prism sends OpenAI's native `json_schema` response format for every model by default, including new families like `gpt-6.x`, `gpt-5.6`, `o3` and `o4`, unknown model names, proxies and fine-tunes of those models. OpenAI treats an omitted `strict` as `strict: true`, so your schema is enforced.

Only a short list of legacy models falls back to JSON mode (a `json_object` response format plus a system message describing your schema): `gpt-4`, `gpt-4-*` snapshots (like `gpt-4-turbo`), `gpt-3.5-turbo*`, `gpt-4o-2024-05-13`, and fine-tunes of those. `o1-mini` and `o1-preview` don't support structured output at all.

Strict schemas have two OpenAI requirements:

- every property must be listed in `requiredFields` (use `nullable: true` for optional values, see the warning above)
- objects must not allow additional properties. Prism's `ObjectSchema` already sends `additionalProperties: false` unless you set `allowAdditionalProperties: true`

If your schema can't meet those rules, either relax strict mode with `'schema' => ['strict' => false]`, or opt out of `json_schema` entirely:

```php
use Prism\Prism\Enums\StructuredMode;

$response = Prism::structured()
    ->using('openai', 'my-custom-model')
    ->withSchema($schema)
    ->usingStructuredMode(StructuredMode::Json) // [!code focus]
    ->withPrompt('...')
    ->asStructured();
```

Asking for `StructuredMode::Structured` explicitly always sends `json_schema`, whatever the model is called.

> [!NOTE]
> Earlier versions sent unknown or custom model names through JSON mode. If you relied on that for a model that can't handle strict schemas, add `->usingStructuredMode(StructuredMode::Json)`.

### Combining Tools with Structured Output

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Facades\Tool;

$schema = new ObjectSchema(
    name: 'weather_analysis',
    description: 'Analysis of weather conditions',
    properties: [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    requiredFields: ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('openai', 'gpt-4o')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Access structured output
dump($response->structured);

// Access tool execution details
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
}
```

> [!IMPORTANT]
> When combining tools with structured output, set `maxSteps` to at least 2. OpenAI automatically uses the `/responses` endpoint and sets `parallel_tool_calls: false`.

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

### Refusals

When an OpenAI model declines a request it returns a refusal instead of text. Prism reports this as `FinishReason::Refusal` and puts the refusal message in `additionalContent['refusal']`. Nothing is thrown, so check the finish reason before trusting the output:

```php
use Prism\Prism\Enums\FinishReason;

$response = Prism::structured()
    ->using('openai', 'gpt-6.1-sol')
    ->withSchema($schema)
    ->withPrompt($prompt)
    ->asStructured();

if ($response->finishReason === FinishReason::Refusal) {
    logger()->warning('OpenAI refused', ['reason' => $response->additionalContent['refusal']]);
}
```

For structured requests `structured` is an empty array on refusal (the refusal is never parsed as JSON). Text and streaming responses use the same finish reason and key; on a stream they're on the `StreamEndEvent`. Tool calls in a refused streamed turn are not executed.

> [!NOTE]
> Earlier versions threw a `PrismException` for structured refusals and ignored refusals in text and streams.

### Commentary and Final Answers

Newer models such as `gpt-6.x` label every assistant message with a `phase`: `commentary` for progress updates (like "I'll check the weather first") and `final_answer` for the answer itself. Prism handles them like this:

- `text` on a step or response is the `final_answer` text when there is one. Steps that end in tool calls usually only have commentary, so their `text` is that commentary.
- `additionalContent['phase']` holds the phase of the step's last message.
- Structured output is decoded from the final answer, never from commentary.

> [!NOTE]
> Earlier versions only read the last output item, so tool-call steps often had empty text. That text can now contain the model's commentary.

### Conversation Replay and Reasoning Items

Each step keeps the response's message, reasoning and function call items, in their original order, in `additionalContent['output_items']`. When you send those messages back (the next tool-loop request, or a conversation you stored and reloaded), Prism replays these items exactly as OpenAI returned them: commentary before its tool calls, every reasoning item with its `encrypted_content`, and each message's `phase`, which OpenAI asks you to resend.

```php
$response = Prism::text()
    ->using('openai', 'gpt-6.1-sol')
    ->withProviderOptions(['store' => false])
    ->withPrompt('What is 17 × 23?')
    ->asText();

// Store $response->messages however you like, then continue the conversation.
$followUp = Prism::text()
    ->using('openai', 'gpt-6.1-sol')
    ->withProviderOptions(['store' => false])
    ->withMessages([...$response->messages, new UserMessage('Now double it.')])
    ->asText();
```

Because reasoning items carry their `encrypted_content`, reasoning survives across turns with `store: false` too, which is what you want for Zero Data Retention. If you rebuild `AssistantMessage` objects from storage, keep `additional_content` intact. Messages without `output_items` (for example ones saved by older versions of Prism) replay the way they always have. To drop replay state on purpose, remove the `output_items` key.

A step that doesn't finish with tool calls (a refusal, or an incomplete or failed response) never runs its function calls, so Prism stores it without them: its `output_items` leave out the `function_call` items and any reasoning that only led up to them, and the step has no `toolCalls`. Continuing after a refusal sends back the refusal message alone, so don't expect tool calls from that turn. The untouched response is still in the step's `raw`.

When streaming, the same keys are on each step's `StepFinishEvent::$additionalContent`, and Prism uses the completed items from `response.output_item.done`.

### Metadata

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'metadata' => [ // [!code focus]
            'project_id' => 23 // [!code focus]
        ] // [!code focus]
    ]); // [!code focus]
```

### Previous Responses

Prism supports OpenAI's [conversation state](https://platform.openai.com/docs/guides/conversation-state#openai-apis-for-conversation-state) with the `previous_response_id` parameter.

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'previous_response_id' => 'response_id' // [!code focus]
    ]); // [!code focus]
```

### Truncation

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'truncation' => 'auto' // [!code focus]
    ]); // [!code focus]
```

### Service Tiers

Prism supports OpenAI's [Service Tier Configuration](https://platform.openai.com/docs/api-reference/chat/create#chat-create-service_tier) via provider-specific meta.

```php
$response = Prism::text()
    ->withProviderOptions([ // [!code focus]
        'service_tier' => 'priority' // [!code focus]
    ]); // [!code focus]
```

> [!WARNING]
> **Priority Service Tiers increase Cost**: Using priority service tier may reduce response time but increases token costs.
>
> 
### Reasoning Models

On the Responses API, `withReasoning(false)` sends `reasoning: {effort: minimal}` unless an explicit `reasoning` provider option is supplied. It does not guarantee that the model performs no reasoning or incurs no reasoning-token cost. Model support for an effort value is determined by OpenAI.

OpenAI's reasoning models like `gpt-5`, `gpt-5-mini`, and `gpt-5-nano` use advanced reasoning capabilities to think through complex problems before responding. These models excel at multi-step problem solving, coding, scientific reasoning, and complex analysis tasks.

#### Reasoning Effort

Control how much reasoning the model performs before generating a response using the `reasoning` parameter:

```php
$response = Prism::text()
    ->using('openai', 'gpt-5')
    ->withPrompt('Write a PHP function to implement a binary search algorithm with proper error handling')
    ->withProviderOptions([ // [!code focus]
        'reasoning' => ['effort' => 'high'] // [!code focus]
    ]) // [!code focus]
    ->asText();
```

Available reasoning effort levels:

- **`low`**: Faster responses with economical token usage, suitable for simpler tasks
- **`medium`**: Balanced approach between speed and reasoning depth (default)
- **`high`**: More thorough reasoning for complex problems requiring deep analysis

Newer models add more levels, and each family accepts a different set (checked against the API in October 2026):

| Model | Accepted `effort` values |
| --- | --- |
| `gpt-6.1-sol`, `gpt-6-astra` | `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-6-sol`, `gpt-6-luna`, `gpt-5.6-*` | `none`, `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-5.5` | `none`, `low`, `medium`, `high`, `xhigh` |

None of these accept `minimal`. Prism passes the value through as given, so an unsupported one comes back as a `PrismException` with OpenAI's error message.

> [!WARNING]
> `gpt-6.x`, `gpt-5.6` and `gpt-5.5` restrict sampling parameters unless reasoning effort is `none`. `temperature` is only accepted at `1`, which changes nothing, and `top_p` is rejected at any value, `1` included. Leave `usingTemperature()` and `usingTopP()` unset for these models, or pair them with `'reasoning' => ['effort' => 'none']` on a model that supports it (not `gpt-6.1-sol` or `gpt-6-astra`), where both are honoured.

> [!NOTE]
> Reasoning models generate internal "reasoning tokens" that help them think through problems. These tokens are included in your usage costs but aren't visible in the response.

#### Reasoning Token Usage

You can track reasoning token usage through the response's usage information:

```php
$response = Prism::text()
    ->using('openai', 'gpt-5-mini')
    ->withPrompt('Refactor this PHP code to use dependency injection')
    ->withProviderOptions([
        'reasoning' => ['effort' => 'medium']
    ])
    ->asText();

// Access reasoning token usage
$usage = $response->firstStep()->usage;
echo "Reasoning tokens: " . $usage->thoughtTokens;
echo "Total completion tokens: " . $usage->completionTokens;
```

#### Text Verbosity

```php
$response = Prism::text()
    ->using('openai', 'gpt-5')
    ->withPrompt('Explain dependency injection')
    ->withProviderOptions([ // [!code focus]
        'text_verbosity' => 'low' // low, medium, high // [!code focus]
    ]) // [!code focus]
    ->asText();
```

#### Store

```php
$response = Prism::text()
    ->using('openai', 'gpt-5')
    ->withPrompt('Give me a summary of the following legal document')
    ->withProviderOptions([ // [!code focus]
        'store' => false // true, false // [!code focus]
    ]) // [!code focus]
    ->asText();
```

`store` is sent on every request Prism makes, including streams and the follow-up requests of a tool loop. With `store: false` Prism still replays reasoning across turns using each reasoning item's `encrypted_content` (see [Conversation Replay and Reasoning Items](#conversation-replay-and-reasoning-items)).

> [!NOTE]
> Earlier versions didn't send `store` on streaming requests.

## Streaming

OpenAI supports streaming responses in real-time. All the standard streaming methods work with OpenAI models:

```php
// Stream events
$stream = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt('Write a story')
    ->asStream();

// Server-Sent Events
return Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Stream Endings

A stream ends with exactly one `StreamEndEvent`, whose `finishReason` reflects how OpenAI finished the response:

- `response.completed` maps to `Stop`, `ToolCalls` or `Refusal`
- `response.incomplete` maps to `Length` (or `ContentFilter`), with the usage OpenAI reported
- `response.failed` emits an `ErrorEvent` first, then ends with `Error`

`additionalContent` on the `StreamEndEvent` includes the response `status` and any `incomplete_details`. Prism only runs your tools when the turn finished with `ToolCalls`. Tool calls from a refused, truncated or failed turn are never executed. Their `ToolCallEvent`s are still emitted, but the `StepFinishEvent` reports the step's `finishReason` and its `output_items` leave them out, so the `AssistantMessage` `StreamedMessages::fromEvents()` builds for that step has no tool calls to replay.

### Streaming Reasoning Models

Reasoning models like `gpt-5` stream their thinking process separately from the final answer:

```php
use Prism\Prism\Enums\StreamEventType;

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::ThinkingDelta) {
        echo '[Thinking] '.$event->delta;
    } elseif ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta;
    }
}
```

### Streaming with Provider Tools

OpenAI's provider tools like `image_generation` emit streaming events during execution, letting you track progress and access results in real-time:

```php
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\Streaming\Events\ProviderToolEvent;

$stream = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withProviderTools([
        new ProviderTool('image_generation'),
    ])
    ->withPrompt('Generate an image of a sunset over mountains')
    ->asStream();

foreach ($stream as $event) {
    if ($event instanceof ProviderToolEvent) {
        // Check when image generation completes
        if ($event->status === 'completed' && isset($event->data['result'])) {
            $imageData = $event->data['result']; // base64 PNG
            file_put_contents('generated.png', base64_decode($imageData));
        }
    }
}
```

For complete details on handling provider tool events, see [Streaming Output](/core-concepts/streaming-output).

### Caching

Automatic caching does not currently work with JsonMode. Please ensure you use StructuredMode if you wish to utilise automatic caching.

OpenAI's `input_tokens` includes the tokens it read from the cache and the tokens it wrote to it. Prism splits that total into buckets that don't overlap, the same way it does for Anthropic, so text, structured and streamed responses all report usage like this:

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `input_tokens` minus cache reads and cache writes, so only the uncached input |
| `cacheReadInputTokens` | `input_tokens_details.cached_tokens` |
| `cacheWriteInputTokens` | `input_tokens_details.cache_write_tokens` |
| `completionTokens` | `output_tokens`, reasoning tokens included |
| `thoughtTokens` | `output_tokens_details.reasoning_tokens` |

Add `promptTokens`, `cacheReadInputTokens` and `cacheWriteInputTokens` together to get OpenAI's `input_tokens` back. `thoughtTokens` is already part of `completionTokens`, so don't add those two.

## Provider Tools

OpenAI offers built-in provider tools that can be used alongside your custom tools. These tools are executed by OpenAI's infrastructure and provide specialized capabilities. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

### Code Interpreter

The OpenAI code interpreter allows your AI to execute Python code in a secure, sandboxed environment. This is particularly useful for mathematical calculations, data analysis, and code execution tasks.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withPrompt('Solve the equation 3x + 10 = 14.')
    ->withProviderTools([
        new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])
    ])
    ->asText();
```

#### Configuration Options

- **container**: Configure the execution environment
  - `type`: Set to `'auto'` for automatic environment selection

## Additional Message Attributes

Adding optional parameters to a `UserMessage` like the `name` field can be done through the `additionalAttributes` parameter.

```php
Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withMessages([
        new UserMessage('Who are you?', additionalAttributes: ['name' => 'TJ']),
    ])
    ->asText();
```

## Image Generation

For image understanding in text requests, set resolution detail on the image itself:

```php
use Prism\Prism\ValueObjects\Media\Image;

$image = Image::fromUrl('https://example.com/diagram.png')
    ->withProviderOptions(['detail' => 'high']);
```

Pass this image through `withPrompt($prompt, [$image])`. Request-level `detail` is not the per-image setting. Choose the detail value supported by your model.

Use the image generation API with a supported OpenAI model. Available options depend on the model.

### Supported Models

Use an available GPT Image model, such as `gpt-image-2.5-sunburst`. DALL-E models and `gpt-image-1` have been removed from the API; see [OpenAI's deprecations](https://developers.openai.com/api/docs/deprecations) and [current image guide](https://developers.openai.com/api/docs/guides/image-generation).

### Basic Usage

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

$image = $response->firstImage();
file_put_contents('landscape.png', base64_decode($image->base64));
```

### Image examples

Each example uses this prompt and the same size and quality. Model labels identify
the requested model; these API responses did not report a model ID. Costs below
are calculated from returned text/image token usage at the generation date's
standard prices; they are not account invoices. Read the [generation metadata](/assets/examples/openai/metadata.json)
for the exact options and file details.

```php
use Prism\Prism\Facades\Prism;

$prompt =
    'An editorial illustration of the Prism AI ecosystem: a clear glass triangular prism floats ' .
    'above a deep navy workbench. One soft white beam enters; hot pink, cyan and teal light ' .
    'paths emerge and connect a small constellation of abstract tool, memory, search and ' .
    'conversation shapes. Precise geometry, tactile glass, crisp soft shadows, generous ' .
    'negative space, balanced square composition, polished technical magazine cover. Keep the ' .
    'scene abstract and welcoming. No people, faces, lettering, labels, watermarks or ' .
    'third-party logos.';
```

<div class="image-examples">

<section class="image-example">

#### gpt-image-2.5-sunburst

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-2.5-sunburst.webp" alt="Glass prism on a navy workbench sends pink, cyan and teal beams toward a wrench, database, magnifying glass and chat bubble." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-2.5-sunburst</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.013700 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

#### gpt-image-2.5-flare

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-flare')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-2.5-flare.webp" alt="Floating glass prism sends pink and cyan light ribbons toward translucent tool, database, search and conversation icons." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-2.5-flare</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.013700 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

#### gpt-image-2

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-2.webp" alt="Clear prism on a dark platform connects illuminated stacked panels, blue cubes, a magnifying glass and conversation bubbles." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-2</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.053210 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

#### gpt-image-1.5

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1.5')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-1.5.webp" alt="Faceted glass prism splits a white beam into pink, cyan and teal paths beside database, search, wrench and conversation symbols." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-1.5</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.038082 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

#### gpt-image-1-mini

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1-mini')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/gpt-image-1-mini.webp" alt="Geometric glass prism above flat pink, blue and teal paths ending in abstract tool and conversation shapes on navy." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>gpt-image-1-mini</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.008660 calculated from returned token usage.</figcaption>
</figure>

</section>

<section class="image-example">

#### chatgpt-image-latest

```php
$response = Prism::image()
    ->using('openai', 'chatgpt-image-latest')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'size' => '1024x1024',
        'quality' => 'medium',
        'n' => 1,
        'background' => 'opaque',
        'output_format' => 'png',
    ])
    ->withClientOptions(['timeout' => 300, 'connect_timeout' => 15, 'allow_redirects' => false])
    ->generate();
```

<figure>
<img src="/assets/examples/openai/chatgpt-image-latest.webp" alt="Glass prism splits light across a navy workbench toward a database, pink chat bubbles, a wrench and gear, and a search panel." width="1024" height="1024" loading="lazy" decoding="async">
<figcaption>Generated with the snippet above. Requested model: <code>chatgpt-image-latest</code>. 1024×1024 · 2026-11-01 · Prism 0.144.1 · $0.037842 calculated from returned token usage.</figcaption>
</figure>

</section>

</div>

`gpt-image-1.5`, `gpt-image-1-mini` and `chatgpt-image-latest` are scheduled to
shut down on December 1, 2026. Use an available current model for new requests;
see [OpenAI’s deprecation schedule](https://developers.openai.com/api/docs/deprecations).

### GPT Image Options

GPT Image offers advanced features including image editing and format control:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A detailed architectural rendering of a modern house')
    ->withProviderOptions([
        'size' => '1536x1024',              // Various sizes supported
        'quality' => 'high',                // low, medium, high, xhigh, max, auto
        'output_format' => 'webp',          // png, webp, jpeg
        'output_compression' => 85,         // Compression level (0-100)
        'background' => 'transparent',      // transparent, opaque, auto
        'moderation' => 'auto',             // auto or low
    ])
    ->generate();
```

### Image Editing with GPT Image

GPT Image supports sophisticated image editing operations using the `withPrompt` method with Image value objects:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Add a vaporwave sunset to the background', [
        Image::fromLocalPath('tests/Fixtures/diamond.png'),
    ])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->withClientOptions(['timeout' => 180])
    ->generate();

file_put_contents('edited-image.png', base64_decode($response->firstImage()->base64));
```

#### Using Masks for Targeted Editing

For precise control over which parts of the image to edit, use a mask image:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Add a vaporwave sunset to the background', [
        Image::fromLocalPath('tests/Fixtures/diamond.png'),
    ])
    ->withProviderOptions([
        'mask' => Image::fromLocalPath('tests/Fixtures/diamond-mask.png'),
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();
```

#### Editing with Multiple Images

You can also edit with multiple images for more complex operations. Use the `as()` method to provide custom filenames for better readability:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Combine these images with a futuristic theme', [
        Image::fromLocalPath('tests/Fixtures/diamond.png')->as('diamond.png'),
        Image::fromLocalPath('tests/Fixtures/sunset.png')->as('sunset-background.png'),
    ])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();
```

### Response Format

GPT Image returns base64 data. Omit `response_format`; select PNG, JPEG or WebP with `output_format`. Inspect returned data before saving it:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Digital artwork')
    ->generate();

$image = $response->firstImage();

if ($image->hasBase64()) {
    file_put_contents('artwork.png', base64_decode($image->base64));
}

if ($image->hasUrl()) {
    echo htmlspecialchars($image->url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

## Audio Processing

Use OpenAI's text-to-speech and speech-to-text APIs through Prism's audio builder.


### Text-to-Speech

OpenAI's Speech API models are scheduled for removal on January 6, 2027. Its replacement uses the Realtime API, which this audio builder does not implement; changing the model ID alone will not migrate a request. See the [audio retirement notice](/core-concepts/audio#text-to-speech) and [OpenAI's guide](https://developers.openai.com/api/docs/guides/text-to-speech).

Convert text into natural-sounding speech with various voice options:

#### Basic TTS Usage

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Hello, welcome to our application!')
    ->withVoice('alloy')
    ->asAudio();

// Save the audio file
$audioData = base64_decode($response->audio->base64);
file_put_contents('welcome.mp3', $audioData);
```

#### High-Definition Audio

For higher quality audio output, use the model:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('This is high-quality audio generation.')
    ->withVoice('nova')
    ->withProviderOptions([
        'response_format' => 'wav',    // Higher quality format
    ])
    ->asAudio();
```

#### Audio Format Options

Control the output format and quality:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Testing different audio formats.')
    ->withVoice('echo')
    ->withProviderOptions([
        'response_format' => 'opus',   // mp3, opus, aac, flac, wav, pcm
        'speed' => 1.25,              // Speed: 0.25 to 4.0
    ])
    ->asAudio();

echo "Audio type: " . ($response->audio->mimeType() ?? 'unknown');
```

For more information on the available options, please refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/text-to-speech).

### Speech-to-Text

Convert audio files into accurate text transcriptions using Whisper:

#### Basic STT Usage

```php
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromLocalPath('/path/to/recording.mp3');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

#### Transcription Usage

OpenAI bills transcription models one of two ways, and `$response->usage` follows suit:

- Token-billed models (like `gpt-4o-transcribe`) report `input_tokens` and `output_tokens`, which land in `promptTokens` and `completionTokens`.
- Duration-billed models (like `whisper-1`) only report the length of the audio. There are no token counts, so `$response->usage` is `null` and the billed seconds are in `$response->additionalContent['usage_seconds']`.

```php
if ($response->usage !== null) {
    echo "Input tokens: {$response->usage->promptTokens}\n";
    echo "Output tokens: {$response->usage->completionTokens}\n";
} else {
    echo 'Billed seconds: '.($response->additionalContent['usage_seconds'] ?? 'not reported')."\n";
}
```

#### Language Detection

For transcription models that require automatic chunking, set `chunking_strategy => 'auto'` in provider options. The audio handler also forwards `language`, `prompt`, `response_format`, `service_tier` and `temperature`.

Whisper can automatically detect the language or you can specify it:

```php
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'es',           // ISO-639-1 code (optional)
        'temperature' => 0.2,         // Lower temperature for more focused results
    ])
    ->asText();
```

#### Response Formats

Get transcriptions in different formats with varying detail levels:

```php
// Standard JSON response
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'json',  // json, text, srt, verbose_json, vtt
    ])
    ->asText();

// Verbose JSON includes timestamps and confidence scores
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'verbose_json',
    ])
    ->asText();

// Access detailed segment information
$segments = $response->additionalContent['segments'] ?? [];
foreach ($segments as $segment) {
    echo "Text: " . $segment['text'] . "\n";
    echo "Start: " . $segment['start'] . "s\n";
    echo "End: " . $segment['end'] . "s\n";
    echo "Confidence: " . ($segment['no_speech_prob'] ?? 'N/A') . "\n\n";
}
```

#### Subtitle Generation

Generate subtitle files directly:

```php
// SRT format subtitles
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'srt',
    ])
    ->asText();

file_put_contents('subtitles.srt', $response->text);

// VTT format subtitles
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'vtt',
    ])
    ->asText();

file_put_contents('subtitles.vtt', $response->text);
```

#### Context and Prompts

Improve transcription accuracy with context:

```php
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'prompt' => 'This is a technical discussion about machine learning and artificial intelligence.',
        'language' => 'en',
        'temperature' => 0.1,         // Lower temperature for technical content
    ])
    ->asText();
```

### Audio File Handling

#### Creating Audio Objects

Load audio from various sources:

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From local file path
$audio = Audio::fromLocalPath('/path/to/audio.mp3');

// From remote URL
$audio = Audio::fromUrl('https://example.com/recording.wav')->fetchUrlContent(); // a URL you trust

// From base64 encoded data
$audio = Audio::fromBase64($base64AudioData, 'audio/mpeg');

// From binary content
$audioContent = file_get_contents('/path/to/audio.wav');
$audio = Audio::fromRawContent($audioContent, 'audio/wav');
```

#### File Size Considerations

Whisper has a file size limit of 25 MB. For larger files, consider:

```php
// Check file size before processing
$audio = Audio::fromLocalPath('/path/to/large-audio.mp3');

if (strlen($audio->rawContent() ?? '') > 25 * 1024 * 1024) { // 25 MiB
    echo "File too large for processing";
} else {
    $response = Prism::audio()
        ->using('openai', 'whisper-1')
        ->withInput($audio)
        ->asText();
}
```

For more information on the available options, please refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/speech-to-text).

## Files and batches

Use [Files](/core-concepts/files) to upload media or batch-input files, and [Batch Processing](/core-concepts/batch) to submit and retrieve asynchronous jobs. These operations use `Prism::files()->using('openai')` and `Prism::batch()->using('openai')`; the model belongs on each batch request item, rather than on the batch client.

## Moderation

The OpenAI integration supports text and image moderation.

### Supported Models

| Model | Description |
|-------|-------------|
| `omni-moderation-latest` | Latest moderation model supporting both text and images |

### Text Moderation

Check text content for potentially harmful or inappropriate material:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Your text to check goes here')
    ->asModeration();

if ($response->isFlagged()) {
    $flagged = $response->firstFlagged();
    // Handle flagged content
}
```

### Image Moderation

Moderate images using the `omni-moderation-latest` model:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(Image::fromUrl('https://example.com/image.png'))
    ->asModeration();

if ($response->isFlagged()) {
    // Handle flagged image
}
```

### Mixed Text and Image Moderation

You can check both text and images in a single request:

```php
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(
        'Check this text',
        Image::fromStoragePath('uploads/user-photo.jpg', 'public'),
        'Another text to check',
        Image::fromUrl('https://example.com/image.png')
    )
    ->asModeration();
```

> [!NOTE]
> When mixing text and images in a single request, text inputs are treated as context/descriptions for the images, not as separate moderation inputs. If you need separate moderation results for text and images, make separate API calls for each type.

### Multiple Inputs

Check multiple inputs at once:

```php
// Multiple text inputs
$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Text 1', 'Text 2', 'Text 3')
    ->asModeration();

// Multiple images
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput([
        Image::fromUrl('https://example.com/image1.png'),
        Image::fromStoragePath('uploads/image2.jpg', 'public'),
    ])
    ->asModeration();
```

### Response Handling

Access moderation results and category information:

```php
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput('Your content here')
    ->asModeration();

// Check if any content was flagged
if ($response->isFlagged()) {
    // Get all flagged results
    $flaggedResults = $response->flagged();
    
    foreach ($flaggedResults as $result) {
        // Access categories
        $categories = $result->categories; // Array of category => bool
        $scores = $result->categoryScores; // Array of category => float
        
        // Check specific categories
        if ($result->categories['hate'] ?? false) {
            // Handle hate content
        }
    }
}
```

For complete moderation documentation, including all available options and use cases, see [Moderation](/core-concepts/moderation).
