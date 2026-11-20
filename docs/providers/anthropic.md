# Anthropic
## Configuration

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
    'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
    // Include beta strings as a comma separated list (e.g. output-128k-2025-02-19, code-execution-2025-05-22).
    'anthropic_beta' => env('ANTHROPIC_BETA', null),
]
```

You can also override `anthropic_beta` per request with `->using('anthropic', $model, ['anthropic_beta' => '...'])`. On top of the configured list, Prism adds the betas a request's provider options need (`fallbacks`, `thinking.display: updates`, `thinking.block_binding`), de-duplicated, on every request and step.
## Models and remote MCP

Use model IDs available to your account, such as `claude-sonnet-5-5` or `claude-opus-5-5`; consult the [Anthropic model catalog](https://platform.claude.com/docs/en/models/overview) for availability and retirement dates. Examples marked as legacy thinking require an older model that still supports token-budget thinking.

The `mcp_servers` request provider option is forwarded to Anthropic for text and structured requests. Configure the remote servers, credentials and required Anthropic beta headers according to [Anthropic's MCP connector](https://platform.claude.com/docs/en/agents-and-tools/mcp-connector). These servers execute on the provider side. To connect MCP tools for execution and authorization in your application, use [Prism MCP](/companion-packages/mcp).

## Errors

HTTP 429 raises `PrismRateLimitedException` with rate-limit buckets and optional retry-after information. HTTP 413 raises `PrismRequestTooLargeException`; 529 raises `PrismProviderOverloadedException`. Other HTTP failures use `PrismException`. See [Error Handling](/advanced/error-handling) for retry and logging guidance.

## Prompt caching

Prompt caching reuses eligible content across requests. Configure a five-minute or one-hour TTL according to the provider's caching requirements.

There are two ways to enable prompt caching:
- Automatic caching
- Explicit cache breakpoints

To enable automatic caching, simply add a single cache_control field at the top level of your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
    ])
    ->withTools([
        Tool::as('cache_me')
            ->for('Return a reusable example result')
            ->using(fn (): string => 'Example result')
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
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withSystemPrompt(
        (new SystemMessage('I am a long re-usable system message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral', 'cacheTtl' => '1h'])
    )
    ->withMessages([
        (new UserMessage('I am a long re-usable user message.'))
            ->withProviderOptions(['cacheType' => 'ephemeral'])
    ])
    ->withTools([
        Tool::as('cache_me')
            ->for('Return a reusable example result')
            ->using(fn (): string => 'Example result')
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

(new UserMessage('I am a long re-usable user message.'))->withProviderOptions(['cacheType' => AnthropicCacheType::ephemeral]);
```
**Important:** To enable prompt caching:
- System messages must use `withSystemPrompt()` or `withSystemPrompts()` (Anthropic does not allow SystemMessages in the messages array)
- User and Assistant messages must use `withMessages()`
- Tools use `withTools()`
- All message types support caching via `withProviderOptions(['cacheType' => 'ephemeral'])`
- You cannot use `withPrompt()` for caching as it doesn't allow adding provider options to individual messages
- Anthropic supports two TTL options: `5m` (default) or `1h`. You can leave the `cacheTtl` unset and Anthropic will use the default TTL of `5m`.

### Cache-write usage and pricing

Text, structured, batch results and completed streamed responses expose `usage->cacheWrite5mInputTokens` and `usage->cacheWrite1hInputTokens` when Anthropic reports the lifetime split. They come from `usage.cache_creation.ephemeral_5m_input_tokens` and `ephemeral_1h_input_tokens`. The existing `cacheWriteInputTokens` remains the reported total; the new fields are breakdowns of it. An unreported counter is `null`; a reported zero is `0`.

Five-minute cache writes cost **1.25 times** the base input token price; one-hour writes cost **2 times** that price. Price each bucket at its own rate and don't charge the combined total again. Cache reads use a separate model-specific rate. See [token usage](/core-concepts/text-generation#token-usage) for a calculation and Anthropic's [current pricing](https://platform.claude.com/docs/en/build-with-claude/prompt-caching#pricing) for modifiers and cache-read rates.

Streaming deltas replace cumulative counts rather than adding repeated reports. Each API step contributes once to a multi-step response. A changed write total without a new split invalidates the earlier split. In a multi-step response, a nonzero write without a split makes that aggregate bucket unknown; zero/null-write steps contribute zero, and entirely unreported totals stay `null`. The TTL you requested doesn't establish the TTL of every write: server tool results can also create 5-minute writes. Keep unknown portions of the price unknown rather than guessing a lifetime.

### Tool result caching

In addition to caching prompts and tool definitions, Prism supports caching tool results. This is particularly useful when making multiple tool calls where results might be referenced repeatedly.

To enable tool result caching, use the `tool_result_cache_type` provider option on your request:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
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
| `high`   | Deep reasoning on complex tasks. The default on most models; Opus 5.5 and Haiku 5.5 default to `medium`. |
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
> Manual thinking with `enabled` and a token budget is deprecated on Claude 4.6 and rejected by the 5.x models. Use adaptive thinking instead. Use the Opus 4.5 example only while that model remains available; consult [model retirement dates](https://platform.claude.com/docs/en/about-claude/model-deprecations).

```php
Prism::text()
    ->using('anthropic', 'claude-opus-4-5-20251101')
    ->withMaxTokens(4096)
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions(['thinking' => ['enabled' => true]])
    ->asText();
```

By default Prism sets the thinking budget to `default_thinking_budget` from the Anthropic provider config (`ANTHROPIC_DEFAULT_THINKING_BUDGET`), or, where that isn't set, to the minimum allowed (1024).

You can override the config (or its default) using `withProviderOptions`:

```php
Prism::text()
    ->using('anthropic', 'claude-opus-4-5-20251101')
    ->withMaxTokens(4096)
    ->withPrompt('What is the meaning of life, the universe and everything in popular fiction?')
    ->withProviderOptions([
        'thinking' => [
            'enabled' => true,
            'budgetTokens' => 2048,
        ],
    ]);
```

Any other `thinking` value is sent to Anthropic as given: for example its own `['type' => 'enabled', 'budget_tokens' => 2048]`, or `['type' => 'adaptive', 'display' => 'summarized']`. `withReasoning(false)` still removes it.

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

Thinking blocks have to go back to Anthropic complete and unmodified, in their original position, when you continue a tool loop. On the 5.x models the API can also check that everything before a thinking block (system prompt, tools and earlier messages) is unchanged. That check is on by default for accounts created on or after 2026-08-31, and you can turn it on with `thinking.block_binding`. Prism handles this for you inside its own tool loops, and it keeps what you need to do the same with conversations you store:

- Every step's `additionalContent['content_blocks']` holds the response's `content` array as returned: thinking, text, tool calls, server tool blocks and fallback markers, in order. The `AssistantMessage` Prism builds for each step carries the same keys, so the stored messages replay the conversation exactly:
  - For `asText()`, persist `$response->messages`. It holds the messages you sent, every step of the tool loop and the final assistant message. `$response->steps->last()->messages` is only what Prism sent for the last step, so it stops before the final answer.
  - For `asStructured()`, which has no `messages`, persist `$response->steps->last()->messages`. Structured steps record the assistant message they produced, so it's already there.
  - For streams, persist the messages `StreamedMessages::fromEvents($events)` builds in your stream callback, after the messages you sent.
- A turn that ends without `tool_use` (a refusal, `max_tokens`) never runs its tool calls, so Prism stores it without them: `content_blocks` leave out its `tool_use` blocks and any server tool use left without a result (except on `pause_turn`, where it goes back unchanged so the turn can [resume](#paused-turns)), and the step has no `toolCalls`. A refused turn replays as whatever text it produced, so don't expect tool calls from it when you continue the conversation. The untouched response is still in the step's `raw`.
- When an `AssistantMessage` is mapped back to Anthropic, Prism uses the first of these it finds: `content_blocks`, then `thinking_blocks` (followed by the message's text and tool calls), then the legacy `thinking`/`thinking_signature` pair. Messages stored before `content_blocks` existed keep replaying as they always have.
- To deliberately drop replay state (for example, when you edit a message), remove those keys from `additionalContent`.

A `cacheType` on an `AssistantMessage` that carries `content_blocks` goes on its last cacheable block; thinking and fallback blocks never get `cache_control`.

### Output limits

Set `withMaxTokens()` within the selected model's output limit. Manual thinking budgets must leave room for the answer within that limit. Check the [model catalog](https://platform.claude.com/docs/en/models/overview) for the selected model.

## Streaming

Claude supports streaming responses in real-time. All the standard streaming methods work with Anthropic models:

```php
// Stream events
$stream = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt('Write a story')
    ->asStream();

// Server-Sent Events
return Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Thinking

When using thinking (adaptive or manual), the reasoning process streams separately from the final answer:

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

### Fine-grained tool streaming

Anthropic’s [fine-grained tool streaming](https://docs.anthropic.com/en/docs/agents-and-tools/tool-use/fine-grained-tool-streaming) lets tool-use input arrive incrementally (with lower latency for large arguments) instead of waiting for a complete JSON payload. Enable it on each tool that should use this behavior with the `eager_input_streaming` provider option:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->withProviderOptions(['eager_input_streaming' => true])
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$stream = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$weatherTool])
    ->withMaxSteps(2)
    ->withPrompt('What is the weather in San Francisco?')
    ->asStream();
```

Use this together with a streaming entrypoint (`asStream()`, `asEventStreamResponse()`, etc.). While the model is still emitting a tool call, streamed input may be partial JSON; only treat tool arguments as final once the stream indicates the tool input is complete.

For complete streaming documentation including Vercel Data Protocol and WebSocket broadcasting, see [Streaming Output](/core-concepts/streaming-output).

### Step metadata in streams

Streams carry the same metadata as `asText()`, split across two events:

- Each `StepFinishEvent` has the step's `additionalContent`: `content_blocks`, `thinking_blocks`, `thinking`, `thinking_signature`, `stop_reason`, `stop_details`, `fallbacks`, `usage_iterations` and the citation/provider tool keys, plus that step's `usage`. These replay blobs stay out of `toArray()`, so they never reach SSE, Data Protocol or broadcast output.
- The `StreamEndEvent` carries the final step's `thinking`, `thinking_signature`, `stop_reason`, `stop_details`, `fallbacks` and `usage_iterations`, but not `content_blocks` or `thinking_blocks`.

If you persist streamed conversations, pass the events your stream callback receives to `Prism\Prism\Streaming\StreamedMessages::fromEvents()` (see [Persisting Streamed Conversations](/core-concepts/streaming-output#persisting-streamed-conversations)). It builds one `AssistantMessage` per step and attaches that step's `additionalContent`, so the stored messages replay exactly like the ones Prism sends inside its own tool loop.

The stream's `finishReason` reflects Anthropic's `stop_reason`, and Prism only runs the tools Claude asked for when the turn actually ended with `tool_use`. A turn that ends in a refusal, `max_tokens` or an error doesn't execute the tool calls it streamed. You still get their `ToolCallEvent`s, but the step's `content_blocks` leave them out (along with any block the stream never finished), the `StepFinishEvent` reports the step's `finishReason`, and the `AssistantMessage` `StreamedMessages` builds for that step has no tool calls.

#### Streams that end early

A stream that ends before Anthropic's `message_stop` (a dropped connection, or a proxy that cuts the response short) did not finish, and is reported that way: an `ErrorEvent` with `errorType` `incomplete_stream` and `recoverable` `false`, then `FinishReason::Error` on the `StepFinishEvent` and `StreamEndEvent`. An Anthropic `error` event ends the stream the same way, with its own `ErrorEvent`.

The `StreamEndEvent`'s `usage` is `null` when Anthropic sent no usage, or when a step ended without its final usage (the `message_delta` that carries `output_tokens`). Its total would otherwise leave out the output generated before the stream stopped, so treat `null` as unknown, not as zero. The step's `StepFinishEvent` still carries whatever usage arrived, for inspection, and so does the `Response` a completion callback receives (its `usage` can't be `null`), so check its `finishReason` before relying on that total.

## Paused turns

Server tools such as web search, web fetch and code execution run in a loop on Anthropic's side. When that loop reaches its iteration limit, the response stops with `pause_turn` (`FinishReason::Pause`), and the turn continues once you send the paused assistant content back as it is.

Prism resumes paused turns for you in `asText()`, `asStructured()` and `asStream()`, within `maxSteps`. The paused response becomes a step with `FinishReason::Pause`, its `content_blocks` go back unchanged (server tool use still waiting on its result included), and the next request picks the turn up. Structured output comes from the final step. A stream stays a single stream: each paused step ends with its own `StepFinishEvent`, and there is one `StreamEndEvent` at the very end.

Every resumed request is a step, so it counts toward `maxSteps`, and the default of 1 leaves no room to resume. You only see `FinishReason::Pause` on the response (or the `StreamEndEvent`) when `maxSteps` runs out before the turn finishes. Raise `maxSteps` when you use server tools, or continue from the messages yourself: send the stored messages back as they are, and the paused assistant message at the end resumes the turn.

```php
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\ValueObjects\ProviderTool;

$response = Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withProviderTools([new ProviderTool(type: 'web_search_20250305', name: 'web_search')])
    ->withMaxSteps(5)
    ->withPrompt('Summarize this week\'s Laravel releases.')
    ->asText();

if ($response->finishReason === FinishReason::Pause) {
    // Still paused after 5 steps: raise maxSteps or continue from $response->messages.
}
```

A paused turn never asks for your tools, so Prism doesn't run client tool calls from one.

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
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
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

### Enabling citations

Anthropic require citations to be enabled on all documents in a request. To enable them, using the `withProviderOptions()` method when building your request:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Document;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withMessages([
        new UserMessage(
            content: "Is the grass green and the sky blue?",
            additionalContent: [
                Document::fromChunks(
                    chunks: ["The grass is green.", "Flamingos are pink.", "The sky is blue."],
                    title: 'The colours of nature'
                )
            ]
        )
    ])
    ->withProviderOptions(['citations' => true])
    ->asText();
```

### Accessing citations

You can access the chunked output with its citations via the additionalContent property on a response, which returns an array of `MessagePartWithCitations`s.

To render footnotes, iterate over the chunks, add citation links to the text and collect the corresponding footnotes:

```php
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;

$messageChunks = $response->additionalContent['citations'];

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $messageChunk  */
foreach ($messageChunks as $messageChunk) {
    $text .= htmlspecialchars($messageChunk->outputText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    
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

When streaming, consume `CitationEvent` objects with `StreamEventType::Citation`. Each carries `citation`, `messageId`, `blockIndex` and optional `metadata`; correlate it with the matching text block. See [Streaming Output](/core-concepts/streaming-output) for event payloads.

## Code execution

Use a provider tool to run code in Anthropic's hosted environment. It executes on Anthropic's infrastructure rather than in your PHP application. Check the [code execution API](https://platform.claude.com/docs/en/agents-and-tools/tool-use/code-execution-tool) for model compatibility and tool versions.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

$response = Prism::text()
    ->using('anthropic', 'claude-opus-5-5')
    ->withPrompt('Use code to calculate the mean of 1, 2, 3, 4 and 5.')
    ->withProviderTools([
        new ProviderTool(type: 'code_execution_20250825', name: 'code_execution'),
    ])
    ->asText();
```

This tool version does not require a beta header. Other provider features can still require entries in `ANTHROPIC_BETA`. See [Provider Tools](/core-concepts/tools-function-calling#provider-tools) for the distinction from tools your application executes.

## Context management

Anthropic can edit the transcript server-side as a conversation grows — clearing
older tool results, clearing thinking blocks, or compacting. Pass the edits
through `context_management`, along with the beta header:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5')
    ->withMaxSteps(24)
    ->withTools($tools)
    ->withPrompt('Audit every page of the ledger.')
    ->withProviderOptions([
        'anthropic_beta' => 'context-management-2025-06-27',
        'context_management' => [
            'edits' => [[
                'type' => 'clear_tool_uses_20250919',
                'trigger' => ['type' => 'input_tokens', 'value' => 30000],
                'keep' => ['type' => 'tool_uses', 'value' => 3],
            ]],
        ],
    ])
    ->asText();
```

`anthropic_beta` accepts a comma-separated string or an array and merges with
the beta features in your configuration.

### Context management results

Read the applied edits from `additionalContent`:

```php
$applied = $response->additionalContent['context_management'] ?? null;

foreach ($applied['applied_edits'] ?? [] as $edit) {
    $edit['type'];                  // clear_tool_uses_20250919
    $edit['cleared_tool_uses'];     // 6
    $edit['cleared_input_tokens'];  // 12678
}
```

Read token counts from each `applied_edits` entry, not its parent object.
Distinguish an absent result from an empty list of edits:

| `additionalContent['context_management']` | meaning |
|---|---|
| absent | the field never reached the API — usually a missing beta header |
| present, `applied_edits` empty | the edit ran and had nothing to clear yet |
| present, `applied_edits` populated | the transcript was edited |

If the field is absent, check the request options and required beta header.

`cleared_input_tokens` accumulates over a conversation rather than reporting a
per-turn delta.

::: info Comparing token counts
Per-step input totals include newly added messages and tool results. Their
difference is not a direct measure of the tokens cleared by context management.
:::

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
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```

**Limitations:**
- Requires a model that supports native structured output
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
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->withProviderOptions(['use_tool_calling' => true])
    ->withToolChoice(\Prism\Prism\Enums\ToolChoice::Auto)
    ->asStructured();
```

**Limitations:**
- Cannot be used with citations (citations are not supported in tool calling mode)

> [!IMPORTANT]
> Without custom tools or thinking, tool calling mode forces Claude to call the structured output tool (`tool_choice` of type `tool`). Claude Opus 5.5, Sonnet 5.5 and Fable 5.1 reject forced tool use with a 400. On those models use native structured output (the default), or keep tool calling mode and pass `->withToolChoice(ToolChoice::Auto)`, which Prism honours. With `Auto` the structured output tool is best effort rather than schema-enforced, so native mode remains the recommendation. `ToolChoice::None` is still rejected in tool calling mode.

Prism adds the "use the structured output tool" instruction once, before the first request, so multi-step tool loops stay append-only (required for preserved thinking on newer models).

#### Combining Custom Tools with Structured Output

You can combine custom tools with structured output to gather data before returning a structured response. Native structured output supports custom tools on compatible models. The example uses the default native mode:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Facades\Tool;

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
    ->using('anthropic', 'claude-sonnet-5-5')
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
> When using custom tools with structured output on Anthropic, you must:
> - Choose a model supporting native structured output and tools, or use the explicit tool-mode controls above
> - Set `maxSteps` to at least 2

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

### Strict Tool Use

You can enable strict validation for tool inputs, which guarantees that tool parameters exactly match your schema through constrained decoding.

To enable strict mode for a tool, use the `strict` provider option:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->withProviderOptions(['strict' => true])
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::text()
    ->using('anthropic', 'claude-sonnet-5-5')
    ->withTools([$weatherTool])
    ->withMaxSteps(2)
    ->withPrompt('What is the weather in San Francisco?')
    ->asText();
```

Strict mode constrains generated arguments to the provider-supported schema.
Application validation and authorization are still required before executing tools.

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

`Image::fromUrl()` sends an image URL to Anthropic as a URL reference. Local files, raw bytes, base64 content and provider file IDs are also supported. Choose a model that accepts images.
