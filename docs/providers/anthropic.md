# Anthropic
## Configuration

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
    // Include beta strings as a comma separated list (e.g. output-128k-2025-02-19, code-execution-2025-05-22).
    'anthropic_beta' => env('ANTHROPIC_BETA', null),
]
```

You can also override `anthropic_beta` per request with `->using('anthropic', $model, ['anthropic_beta' => '...'])`. On top of the configured list, Prism adds the betas a request's provider options need (`fallbacks`, `thinking.display: updates`, `thinking.block_binding`), de-duplicated, on every request and step.
## Prompt caching

Anthropic's prompt caching feature allows you to drastically reduce latency and your API bill when repeatedly re-using blocks of content within five minutes or one hour of each other, depending on the Anthropic compatible TTL option you provide.

There are two ways to enable prompt caching:
- Automatic caching
- Explicit cache breakpoints

To enable automatic caching, simply add a single cache_control field at the top level of your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
    ])
    ->withTools([
        Tool::as('cache me')
    ])
    ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']])
    ->asText();
```

We support Anthropic explicit cache breakpoints on:

- System Messages (text only)
- User Messages (Text, Image and PDF (pdf only))
- Assistant Messages (text only)
- Tools

The API for enabling prompt caching is the same for all, enabled via the `withProviderOptions()` method. Where a UserMessage contains both text and an image or document, both will be cached.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '1h'])
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->withTools([
        Tool::as('cache me')
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->asText();
```

If you prefer, you can use the `AnthropicCacheType` Enum like so:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Providers\Anthropic\Enums\AnthropicCacheType;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

(new UserMessage('I am a long re-usable user message.'))->withProviderOptions(['cacheType' => AnthropicCacheType::ephemeral])
```
**Important:** To enable prompt caching:
- System messages must use `withSystemPrompt()` or `withSystemPrompts()` (Anthropic does not allow SystemMessages in the messages array)
- User and Assistant messages must use `withMessages()`
- Tools use `withTools()`
- All message types support caching via `withProviderOptions(['cacheType' => 'ephemeral'])`
- You cannot use `withPrompt()` for caching as it doesn't allow adding provider options to individual messages
- Anthropic supports two TTL options: `5m` (default) or `1h`. You can leave the `cacheTtl` unset and Anthropic will use the default TTL of `5m`.

### Tool result caching

In addition to caching prompts and tool definitions, Prism supports caching tool results. This is particularly useful when making multiple tool calls where results might be referenced repeatedly.

To enable tool result caching, use the `tool_result_cache_type` provider option on your request:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('anthropic', 'claude-3-5-sonnet-20241022')
    ->withMaxSteps(30)
    ->withTools([new WeatherTool()])
    ->withProviderOptions([
        'tool_result_cache_type' => 'ephemeral'
    ])
    ->withPrompt('Check the weather in New York, London, Tokyo, Paris, and Sydney')
    ->asText();
```

When multiple tool results are returned, Prism automatically applies caching to only the last result, which caches all preceding results as well. The breakpoint rolls forward to the newest tool result on each step of a tool loop (text, streaming and structured alike), so a long loop never exceeds Anthropic's 4-cache-breakpoint limitation.

Please ensure you read Anthropic's [prompt caching documentation](https://docs.anthropic.com/en/docs/build-with-claude/prompt-caching), which covers some important information on e.g. minimum cacheable tokens and message order consistency.

## Thinking

Claude can reason before it answers. On current models that reasoning is **adaptive**: Claude decides when and how deeply to think, and you steer it with `effort`. Claude Opus 5.5, Sonnet 5.5, Haiku 5.5 and Fable 5.1 think by default, even when you send no thinking option at all. Older models (Opus 4.5, Sonnet 4.5, Sonnet 3.7 and earlier) use manual thinking with a fixed token budget.

Please refer to Anthropic's [thinking documentation](https://platform.claude.com/docs/en/build-with-claude/thinking) for limitations and behaviours when using thinking with caching and/or tools.

### Adaptive thinking (recommended)

```php
use Prism\Prism\Facades\Prism;

Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['type' => 'adaptive']])
    ->asText();
```

On the 5.x models thinking is on without this option, and the thinking text is hidden by default (`display: omitted`): you get thinking blocks with an empty `thinking` field and a `signature`. To read a summary of Claude's reasoning, ask for it with `display`:

```php
Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withPrompt('Analyze the trade-offs between microservices and monolithic architectures')
    ->withProviderOptions([
        'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
        'effort' => 'high',
    ])
    ->asText();
```

Use the `effort` parameter to guide how much work Claude puts into the whole response, thinking included:

| Level    | Description                                                               |
|----------|---------------------------------------------------------------------------|
| `max`    | Maximum capability with no constraints on thinking depth.                 |
| `xhigh`  | Deeper than `high` for the hardest tasks.                                 |
| `high`   | Deep reasoning on complex tasks. This is the default.                     |
| `medium` | Balanced approach with moderate token savings.                            |
| `low`    | Most efficient. Significant token savings with some capability reduction. |

Which levels a model accepts varies; an unsupported level comes back as a `PrismException` from the API.

> [!TIP]
> The `effort` parameter can also be used without a thinking option, in which case it controls overall token spend for text responses and tool calls.

Works identically with `Prism::structured()` and streaming.

### The thinking option

When you set `thinking.type`, Prism sends your `thinking` array to Anthropic as given, using the API's field names. That means anything the API accepts works without a Prism release:

| Option | Example | Notes |
|--------|---------|-------|
| `type` | `adaptive`, `enabled`, `disabled`, `between_tools` | Required for passthrough. |
| `display` | `summarized`, `omitted`, `updates` | `updates` is in beta; Prism adds the `thinking-display-updates-2026-08-18` header for you. |
| `block_binding` | `['prefix_mismatch_behavior' => 'error']` | Prefix enforcement for preserved thinking (`error` or `drop_block`); Prism adds the `thinking-binding-controls-2026-08-01` header for you. |
| `budget_tokens` | `2048` | Only meaningful for `type: enabled`. Prism's `budgetTokens` alias is translated for `enabled`, and the config default is used when neither is set. |

Prism doesn't validate these against the model. The API's answer is the source of truth, and a rejected combination surfaces as a `PrismException`. As of this writing:

- `enabled` (with `budget_tokens`) returns a 400 on all of the 5.x models above.
- `disabled` returns a 400 on Opus 5.5, Sonnet 5.5 and Fable 5.1. Haiku 5.5 accepts it at `high` effort or below.
- Sonnet 5.5's lowest setting is `between_tools`, which turns off up-front thinking but still returns the short progress updates it writes between tool calls as thinking blocks. It's accepted at `high` effort or below and takes no other field. Opus 5.5 rejects it.

```php
// Turn up-front thinking off on Sonnet 5.5
Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt('Summarize this article in one sentence.')
    ->withProviderOptions(['thinking' => ['type' => 'between_tools']])
    ->asText();
```

### Manual thinking (legacy)

> [!WARNING]
> Manual thinking with `enabled` and a token budget is deprecated on Claude 4.6 and rejected by the 5.x models. Use adaptive thinking instead. Manual thinking is still required for older models (Sonnet 4.5, Opus 4.5, Sonnet 3.7, etc.).

```php
Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['enabled' => true]])
    ->asText();
```

By default Prism will set the thinking budget to the value set in config, or where that isn't set, the minimum allowed (1024).

You can override the config (or its default) using `withProviderOptions`:

```php
Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions([
        'thinking' => [
            'enabled' => true,
            'budgetTokens' => 2048,
        ],
    ]);
```

Note that thinking tokens count towards output tokens, so you will be billed for them and your token budget must be less than the max tokens you have set for the request. If you expect a long response, ensure there's enough tokens left for the response — i.e. does (maxTokens - thinkingBudget) leave a sufficient remainder.

### Inspecting the thinking block

Prism reads thinking from the response whenever the model returns it, whether or not you sent a thinking option. You can access it via the `additionalContent` property on either the Response or the relevant step:

| Key | Contents |
|-----|----------|
| `thinking_blocks` | Every `thinking` and `redacted_thinking` block, in order, exactly as returned. |
| `thinking` | The text of the first `thinking` block (an empty string when display is omitted). |
| `thinking_signature` | The signature of that same block. |

```php
$response = Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['type' => 'adaptive', 'display' => 'summarized']])
    ->asText();

$response->additionalContent['thinking'];
```

When using tools, Anthropic returns thinking on the tool call steps, so look at the steps:

```php
$response->steps->first()->additionalContent['thinking_blocks'];
```

> [!NOTE]
> Claude may skip thinking for simple queries, in which case no thinking keys are present.

### Thinking tokens

When Anthropic reports `usage.output_tokens_details.thinking_tokens`, Prism puts it on `$response->usage->thoughtTokens`, for text, structured and streamed responses, per step and summed across a tool loop. `completionTokens` is still Anthropic's `output_tokens`, which already includes those thinking tokens, so don't add the two together. Older models don't send the field, so `thoughtTokens` stays `null` (a streamed tool loop's total reports `0`).

```php
$response->usage->completionTokens; // everything Claude generated, thinking included
$response->usage->thoughtTokens;    // the thinking part of it
```

### Replaying conversations with thinking

Thinking blocks have to go back to Anthropic complete and unmodified, in their original position, when you continue a tool loop. On newer models the API also checks that everything before a thinking block (system prompt, tools and earlier messages) is unchanged. Prism handles this for you inside its own tool loops, and it keeps what you need to do the same with conversations you store:

- Every step's `additionalContent['content_blocks']` holds the response's `content` array as returned: thinking, text, tool calls, server tool blocks and fallback markers, in order. The `AssistantMessage` Prism builds for each step carries the same keys, so the stored messages replay the conversation exactly:
  - For `asText()`, persist `$response->messages`. It holds the messages you sent, every step of the tool loop and the final assistant message. `$response->steps->last()->messages` is only what Prism sent for the last step, so it stops before the final answer.
  - For `asStructured()`, which has no `messages`, persist `$response->steps->last()->messages`. Structured steps record the assistant message they produced, so it's already there.
  - For streams, persist the messages a `StreamCollector` gives you, after the messages you sent.
- A turn that ends without `tool_use` (a refusal, `max_tokens`) never runs its tool calls, so Prism stores it without them: `content_blocks` leave out its `tool_use` blocks and any server tool use left without a result (except on `pause_turn`, which Anthropic resumes), and the step has no `toolCalls`. A refused turn replays as whatever text it produced, so don't expect tool calls from it when you continue the conversation. The untouched response is still in the step's `raw`.
- When an `AssistantMessage` is mapped back to Anthropic, Prism uses the first of these it finds: `content_blocks`, then `thinking_blocks` (followed by the message's text and tool calls), then the legacy `thinking`/`thinking_signature` pair. Messages stored before `content_blocks` existed keep replaying as they always have.
- To deliberately drop replay state (for example, when you edit a message), remove those keys from `additionalContent`.

A `cacheType` on an `AssistantMessage` that carries `content_blocks` goes on its last cacheable block; thinking and fallback blocks never get `cache_control`.

### Extended output mode

Claude Sonnet 3.7 also brings extended output mode which increase the output limit to 128k tokens.

This feature is currently in beta, so you will need to enable to by adding `output-128k-2025-02-19` to your Anthropic anthropic_beta config (see [Configuration](#configuration) above).

## Streaming

Claude supports streaming responses in real-time. All the standard streaming methods work with Anthropic models:

```php
// Stream events
$stream = Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt('Write a story')
    ->asStream();

// Server-Sent Events
return Prism::text()
    ->using('anthropic', 'claude-3-7-sonnet-latest')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Thinking

When using thinking (adaptive or manual), the reasoning process streams separately from the final answer:

```php
use Prism\Prism\Enums\StreamEventType;

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::ThinkingDelta => echo "[Thinking] " . $event->delta,
        StreamEventType::TextDelta => echo $event->delta,
        default => null,
    };
}
```

For complete streaming documentation including Vercel Data Protocol and WebSocket broadcasting, see [Streaming Output](/core-concepts/streaming-output).

### Step metadata in streams

Streams carry the same metadata as `asText()`, split across two events:

- Each `StepFinishEvent` has the step's `additionalContent`: `content_blocks`, `thinking_blocks`, `thinking`, `thinking_signature`, `stop_reason`, `stop_details`, `fallbacks`, `usage_iterations` and the citation/provider tool keys, plus that step's `usage`. These replay blobs stay out of `toArray()`, so they never reach SSE, Data Protocol or broadcast output.
- The `StreamEndEvent` carries the final step's `thinking`, `thinking_signature`, `stop_reason`, `stop_details`, `fallbacks` and `usage_iterations`, but not `content_blocks` or `thinking_blocks`.

If you persist streamed conversations, run the stream through `Prism\Prism\Streaming\StreamCollector`. It builds one `AssistantMessage` per step and attaches that step's `additionalContent`, so the stored messages replay exactly like the ones Prism sends inside its own tool loop.

The stream's `finishReason` reflects Anthropic's `stop_reason`, and Prism only runs the tools Claude asked for when the turn actually ended with `tool_use`. A turn that ends in a refusal, `max_tokens` or an error doesn't execute the tool calls it streamed. You still get their `ToolCallEvent`s, but the step's `content_blocks` leave them out (along with any block the stream never finished), the `StepFinishEvent` reports the step's `finishReason`, and the `AssistantMessage` a `StreamCollector` builds for that step has no tool calls.

## Refusals

Claude's safety classifiers can decline a request. That comes back as a normal response, not an error, with `FinishReason::Refusal`. Check the finish reason rather than the content, since a refusal can arrive before any output or part-way through it:

```php
use Prism\Prism\Enums\FinishReason;

$response = Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withPrompt($prompt)
    ->asText();

if ($response->finishReason === FinishReason::Refusal) {
    $response->additionalContent['stop_details'];
    // ['type' => 'refusal', 'category' => 'cyber', 'explanation' => '...']
}
```

Every step and response carries the raw `stop_reason` in `additionalContent`, and `stop_details` whenever Anthropic sends one. Both `category` and `explanation` can be `null`. The same keys are on the `StepFinishEvent` and `StreamEndEvent` when streaming. `model_context_window_exceeded` maps to `FinishReason::Length`.

A refusal before any output still adds an assistant message to `$response->messages`, but that message has nothing to replay. Prism leaves it out of the next request, since an empty assistant turn reads to the model as a reply it skipped. The refused user prompt stays in the history, though, so if you continue the conversation as is, the model will usually decline again. Drop the refused exchange from the messages you send back, or use [fallbacks](#fallbacks) to get an answer in the same call.

## Fallbacks

Anthropic can retry a declined request on another model inside the same API call. Pass `fallbacks` as a provider option, either `'default'` (Anthropic picks the recommended fallback for the refusal category) or a list of up to three models with optional overrides. Prism sends it as the top-level `fallbacks` field and adds the `server-side-fallback-2026-07-01` beta header for you, on text, structured and streaming requests and on every step of a tool loop.

```php
$response = Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withPrompt($prompt)
    ->withProviderOptions([
        'fallbacks' => 'default',
        // or: 'fallbacks' => [['model' => 'claude-opus-4-8']],
    ])
    ->asText();

$response->meta->model;                            // the model that served the response
$response->additionalContent['fallbacks'];         // [['from' => 'claude-opus-5-5', 'to' => 'claude-opus-4-8']]
$response->additionalContent['usage_iterations'];  // one entry per attempt, with its own model
```

A few things worth knowing:

- `fallbacks` is only present when the response contains a `fallback` block. With sticky routing, a later turn can go straight to the fallback model; that turn has no `fallback` block, so look for a `fallback_message` entry in `usage_iterations` and at `meta->model`.
- `usage_iterations` is Anthropic's raw `usage.iterations` for that step. Prism's `Usage` keeps the API's meaning: the attempt that produced the returned message, never tokens from different models added together. Bill each attempt at its own `model`'s rates, and for a tool loop sum `usage_iterations` across `$response->steps`, since each step is a separate API call.
- Replayed turns follow Anthropic's echo rules for you: the `fallback` blocks stay where they appeared, text and everything after the last fallback is kept, and declined thinking, connector text, tool calls and unpaired server tool calls before it are dropped. Tool calls made before the last fallback are never executed.
- Claude Haiku 5.5 has no server-side fallback, and `fallbacks` isn't available on the Message Batches API or on Bedrock, Google Cloud or Foundry.

## Documents

Anthropic supports PDF, text and markdown documents. Note that Anthropic uses vision to process PDFs under the hood, and consequently there are some limitations detailed in their [feature documentation](https://docs.anthropic.com/en/docs/build-with-claude/pdf-support).

See the [Documents](/input-modalities/documents.html) on how to get started using them.

Anthropic also supports "custom content documents", separately documented below, which are primarily for use with citations.

### Custom content documents

Custom content documents are primarily for use with citations (see below), if you need citations to reference your own chunking strategy.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(["The grass is green.", "Flamingos are pink.", "The sky is blue."])
            ]
        )
    ])
    ->asText();
```

## Citations

Prism supports [Anthropic's citations feature](https://docs.anthropic.com/en/docs/build-with-claude/citations) for both text and structured.

Please note that citations cannot be used with native structured output mode (the default). If you need citations with structured output, use tool calling mode via `withProviderOptions(['use_tool_calling' => true, 'citations' => true])`. Note however that citations with tool calling mode can produce unreliable output, so you should implement proper error handling.

## Code execution

Anthropic offers built-in code execution capabilities that allow your AI to run code in a secure environment. This is a provider tool that executes code using Anthropic's infrastructure. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

To enable code execution, you will first need to enable the beta feature.

Either in prism/config.php:

```php
        'anthropic' => [
            ...
            'anthropic_beta' => 'code-execution-2025-05-22',
        ],

```

Or in your env file (assuming config/prism.php reflects the default prism setup):

```
ANTHROPIC_BETA="code-execution-2025-05-22"
```

You may then use code execution as follows:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

Prism::text()
    ->using('anthropic', 'claude-3-5-haiku-latest')
    ->withPrompt('Solve the equation 3x + 10 = 14.')
    ->withProviderTools([new ProviderTool(type: 'code_execution_20250522', name: 'code_execution')])
    ->asText();

```

### Enabling citations

Anthropic require citations to be enabled on all documents in a request. To enable them, using the `withProviderOptions()` method when building your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(
                    chunks: ["The grass is green.", "Flamingos are pink.", "The sky is blue."],
                    title: 'The colours of nature',
                    context: 'The go-to textbook on the colours found in nature!'
                )
            ]
        )
    ])
    ->withProviderOptions(['citations' => true])
    ->asText();
```

### Accessing citations

You can access the chunked output with its citations via the additionalContent property on a response, which returns an array of `MessagePartWithCitations`s.

As a rough worked example, let's assume you want to implement footnotes. You'll need to loop through those chunks and (1) re-construct the message with links to the footnotes; and (2) build an array of footnotes to loop through in your frontend.

```php
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;

$messageChunks = $response->additionalContent['citations'];

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $messageChunk  */
foreach ($messageChunks as $messageChunk) {
    $text .= $messageChunk->outputText;
    
    /** @var Citation $citation */
    foreach ($messageChunk->citations as $citation) {
        $footnotes[] = [
            'id' => $footnoteId,
            'document_title' => $citation->sourceTitle,
            'reference_start' => $citation->sourceStartIndex,
            'reference_end' => $citation->sourceEndIndex
        ];
    
        $text .= '<sup><a href="#footnote-'.$footnoteId.'">'.$footnoteId.'</a></sup>';
    
        $footnoteId++;
    }
}
```

Note that when using streaming, Anthropic does not stream citations in the same way. Instead, of building the context as above, yield text to the browser in the usual way and pair text up with the relevant footnote using the `citationIndex` on the text chunk's additionalContent parameter.

## Considerations
### Message Order

- Message order matters. Anthropic is strict about the message order being:

1. `UserMessage`
2. `AssistantMessage`
3. `UserMessage`

### Structured Output

Prism supports two approaches for structured output with Anthropic models:

#### Native Structured Outputs (Default)

Prism uses Anthropic's native structured outputs by default. This provides guaranteed schema compliance through constrained decoding — no beta header required.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Anthropic, 'claude-sonnet-4-5-20250929')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```

**Benefits of native structured outputs:**
- **Always valid JSON**: No more parsing errors or malformed responses
- **Type safe**: Guaranteed field types and required fields

**Limitations:**
- Only available on Claude Sonnet 4.5+ and Claude Opus 4.1+
- Cannot be used with citations
- Some JSON Schema features are not supported (see [Schema Limitations](#schema-limitations))

#### Tool Calling Mode
For older models that don't support native structured outputs, or when dealing with complex content or non-English text that may contain quotes, you can use tool calling mode:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->withSchema(new ObjectSchema(
        'weather_report',
        'Weather forecast with recommendations',
        [
            new StringSchema('forecast', 'The weather forecast'),
            new StringSchema('recommendation', 'Clothing recommendation')
        ],
        ['forecast', 'recommendation']
    ))
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->withProviderOptions(['use_tool_calling' => true])
    ->asStructured();
```

**Benefits of tool calling mode:**
- More reliable JSON parsing, especially with quotes and special characters
- Better handling of non-English content (Chinese, Japanese, etc.)
- Reduced risk of malformed JSON responses
- Compatible with thinking mode

**Limitations:**
- Cannot be used with citations (citations are not supported in tool calling mode)
- Slightly more complex under the hood but identical API usage

> [!IMPORTANT]
> Without custom tools or thinking, tool calling mode forces Claude to call the structured output tool (`tool_choice` of type `tool`). Claude Opus 5.5, Sonnet 5.5 and Fable 5.1 reject forced tool use with a 400. On those models use native structured output (the default), or keep tool calling mode and pass `->withToolChoice(ToolChoice::Auto)`, which Prism honours. With `Auto` the structured output tool is best effort rather than schema-enforced, so native mode remains the recommendation. `ToolChoice::None` is still rejected in tool calling mode.

Prism adds the "use the structured output tool" instruction once, before the first request, so multi-step tool loops stay append-only (required for preserved thinking on newer models).

#### Combining Custom Tools with Structured Output

You can combine custom tools with structured output to gather data before returning a structured response. This requires tool calling mode to be enabled:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

$schema = new ObjectSchema(
    'weather_analysis',
    'Analysis of weather conditions',
    [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('anthropic', 'claude-3-5-sonnet-latest')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withProviderOptions(['use_tool_calling' => true]) // Required for Anthropic
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
> When using custom tools with structured output on Anthropic, you must:
> - Set `use_tool_calling: true` in provider options
> - Set `maxSteps` to at least 2

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

### Strict Tool Use

You can enable strict validation for tool inputs, which guarantees that tool parameters exactly match your schema through constrained decoding.

To enable strict mode for a tool, use the `strict` provider option:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->withProviderOptions(['strict' => true])
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5-20250929')
    ->withTools([$weatherTool])
    ->withPrompt('What is the weather in San Francisco?')
    ->asText();
```

**Benefits of strict tool use:**
- Functions receive correctly-typed arguments every time
- No need to validate tool inputs
- Eliminates runtime errors from type mismatches
- Production-ready agents that work consistently

### Schema Limitations

When using native structured outputs, certain JSON Schema features are not supported by Anthropic's constrained decoding:

**Not Supported:**
- Recursive schemas
- Numerical constraints (`minimum`, `maximum`, `multipleOf`)
- String constraints (`minLength`, `maxLength`)
- Complex regex patterns (lookahead/lookbehind, backreferences)
- External `$ref` definitions

**Supported:**
- All basic types (object, array, string, integer, number, boolean, null)
- `enum` for simple types (strings, numbers, booleans)
- `anyOf` and `allOf` (with limitations)
- `required` and `additionalProperties: false`
- String formats (`date-time`, `email`, `uri`, `uuid`, etc.)

If you use an unsupported feature, Anthropic will return a 400 error with details. For more information, see [Anthropic's structured outputs documentation](https://docs.anthropic.com/en/api/structured-outputs).

## Limitations
### Messages

Most providers' API include system messages in the messages array with a "system" role. Anthropic does not support the system role, and instead has a "system" property, separate from messages.

Therefore, for Anthropic we:
* Filter all `SystemMessage`s out, omitting them from messages.
* Always submit the prompt defined with `->withSystemPrompt()` at the top of the system prompts array.
* Move all `SystemMessage`s to the system prompts array in the order they were declared.

### Images

Does not support `Image::fromURL`
