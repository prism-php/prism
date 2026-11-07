# Perplexity

> [!IMPORTANT]
> Prism uses Perplexity's Agent API (`POST /v1/agent`). Review the model mappings
> and request behavior below when migrating from Sonar.

## Migrating from Sonar

**Your model strings keep working.** Perplexity replaced model slugs with presets, and Prism
translates the retired slugs for you. Since 0.141.0 the outgoing field is
`effort`, as required by the [current Agent API schema](https://docs.perplexity.ai/api-reference/agent-post).
The existing tier mapping is preserved for billing compatibility:

| You pass | Prism sends |
|---|---|
| `sonar` | `effort: fast` |
| `sonar-pro` | `effort: low` |
| `sonar-reasoning` | `effort: low` (slug retired upstream) |
| `sonar-reasoning-pro` | `effort: low` |
| `sonar-deep-research` | `effort: medium` |

A preset name given directly (`fast`, `low`, `medium`, `high`, `xhigh`, `wide-research`) is
passed through, and anything else is treated as a real model id — `openai/gpt-5.6-sol` is sent
as a one-element `models` list. Model ids must use `provider/model` format.
An explicitly supplied `models` chain wins and must contain 1..5 valid ids.
An empty chain fails locally. Retired Sonar slugs cannot be fallback ids.

Perplexity's current migration guide suggests different tiers for some Sonar
names. Prism preserves its existing choices; select `effort` explicitly to change
tiers. The public `preset` option remains a compatibility alias for `effort`.

### Overriding a preset

`sonar-deep-research` maps to `medium`. To select a different tier, set the preset explicitly:

```php
Prism::text()
    ->using(Provider::Perplexity, 'sonar-deep-research')
    ->withProviderOptions(['preset' => 'high']);
```

### `withSystemPrompt()` replaces the preset's own prompt

`withSystemPrompt()` sets the Agent API's `instructions` field, replacing the
preset's built-in system prompt rather than appending to it.

Prism only sends `instructions` when you actually set a system prompt, so presets keep their
own by default.

### Failures arrive as HTTP 200

An incomplete, failed or cancelled run can return HTTP 200. For text, structured
and streaming requests, Prism throws `PrismRunException`, which extends
`PrismException`; it does not return partial output as a successful response.

For streams, catch the exception around iteration of `asStream()`. Text deltas
already yielded are provisional; an unsuccessful terminal event throws before
`TextCompleteEvent` or `StreamEndEvent` is emitted. Its run snapshot remains
available through the same accessors below. An incomplete run does not imply a
token limit: inspect `incompleteReason()` rather than assuming a larger token
budget will resolve it.

An unrecognized non-progress stream status ends with `FinishReason::Unknown`;
it does not confirm successful completion. A `rate_limit_exceeded` error keeps
its `PrismRateLimitedException` classification, including on a terminal run
snapshot, so callers can retain their backoff handling.

Use `code()` to distinguish `run_incomplete`, `run_failed` and `run_cancelled`
without parsing the exception message. The exception provides:

| Accessor | Result |
|---|---|
| `status()` | Provider run status, or `null` if unavailable |
| `runId()` | Provider run ID, or `null` if absent |
| `providerRequestId()` | Transport request id if supplied; distinct from the run id |
| `providerCode()` / `providerError()` | Provider's own error code and error object |
| `partialText()` | Partial answer text, accessed explicitly |
| `incompleteReason()` | `incomplete_details.reason`, or `null` if absent |
| `incompleteDetails()` | Full provider incomplete-details array |
| `output()` | Partial output items, including citation annotations and search-result sources |
| `citations()` | Inline citation annotations in provider order, without deduplication; source items remain in `output()` |
| `usage()` | Normalized `Usage`, or `null` if usage was not reported |
| `usageDetails()` | Full provider usage ledger, including cost and tool-call breakdowns |

`httpStatus` is `200`; PHP's numeric `getCode()` remains `200` for compatibility.
Use `code()` for the run outcome. Usage records resources spent, not successful
completion: your application decides whether to bill an unsuccessful run.

Partial content is available only through explicit accessors, not appended to
the message or copied into the public `responseBody` field. Treat returned
diagnostics as sensitive data and apply your retention and access policies;
do not log or serialize the complete exception indiscriminately.

Messages include the incomplete reason, provider code/message, and available
request and response ids. If no provider message exists, that absence is explicit.
Provider messages are bounded at 8192 bytes with an explicit truncation marker.
HTTP failures raise `PrismPerplexityRequestException` (a `PrismException`), with
request-id/code accessors and an explicit `providerResponseBody()` accessor for
the bounded raw error body. Laravel's shortened exception summary is not used.
Keep this raw body private; a provider may echo sensitive input.

### What comes back

```php
$response->additionalContent['search_results'];   // structured sources, not prose
$response->additionalContent['fetch_url_results'];
$response->additionalContent['resolved_model'];   // which model the preset actually used
```

`resolved_model` identifies the model selected by the preset. Use it when
interpreting usage and applying your application's provider policies.

An **empty** `search_results` on a completed run is normal — a preset may answer without
searching — so do not treat a missing source list as an error.

### Cost is reported, not estimated

`cost` contains the provider-reported request cost:

```php
$response->usage->cost;   // e.g. 0.005, or null if the response carried none
```

Only two Prism providers do this — Perplexity and OpenRouter. Everywhere else `cost` is null and
you have to derive it. A `0.0` here is a genuine answer, not a missing one.

The full provider usage ledger, including its cost breakdown, is available in `$response->additionalContent['usage']` when reported.

> [!NOTE]
> `tools: []` does not disable a preset's built-in tools. Perplexity exposes no public field
> that does.

## Configuration

```php
'perplexity' => [
    'api_key' => env('PERPLEXITY_API_KEY', ''),
    'url' => env('PERPLEXITY_URL', 'https://api.perplexity.ai'),
    'deep_research_timeout' => (int) env('PERPLEXITY_DEEP_RESEARCH_TIMEOUT', 600),
]
```

## Server tools and request options

Perplexity documents that deep research can take
[several minutes](https://docs.perplexity.ai/docs/agent-api/background-mode).
Prism chooses 600 seconds for `sonar-deep-research` and the `medium`, `high`,
`xhigh` and `wide-research` tiers. This is a Prism default, not a provider SLA.
`withClientOptions(['timeout' => 180])` overrides it per request. Connection
timeouts retain the ordinary setting. No retry is added: a timed-out POST may
already have started a billable run. Background orchestration stays in the
separate Perplexity satellite; this change keeps the core API synchronous.

`max_steps` must be an integer in 1..500, `max_output_tokens` must be positive,
and explicit Anthropic models require an output-token bound. The current schema
does not accept `metadata`; Prism refuses it and asks you to keep it locally.

Passing custom tools with `withTools()` raises `PrismException`. Perplexity executes its own server tools; request those through the `tools` provider option:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('perplexity', 'low')
    ->withPrompt('Find recent PHP documentation about enums.')
    ->withProviderOptions([
        'tools' => [['type' => 'web_search']],
        'search_domain_filter' => ['php.net'],
        'search_recency_filter' => 'month',
    ])
    ->asText();
```

The search-filter keys `search_domain_filter`, `search_recency_filter`, `search_after_date_filter`, `search_before_date_filter`, `last_updated_after_filter` and `last_updated_before_filter` are moved into the `web_search` tool's `filters`. Prism adds that tool when needed. Filters explicitly set on a tool take precedence over translated request filters. A tool's `filters` value must be an array.

Supported request options also include `effort`, `background`, `max_steps`, `models`, `previous_response_id`, `store`, `skills` and `language_preference`. `reasoning_effort` maps to `reasoning.effort`; an explicit `reasoning` object takes precedence. These are Agent API options, separate from Prism's local `withMaxSteps()` tool-loop limit. For background-job retrieval, cancellation and polling, see [Prism Perplexity](https://github.com/Particle-Academy/prism-perplexity).

`search_mode` is refused when supplied. `return_images` and `return_related_questions` are refused unless their value is exactly `false`; those two no-op values are accepted and omitted. Remove unsupported Sonar options or express the request through a preset, server tool or structured prompt.

## Documents

This integration sends text messages to the Perplexity Agent API. It does not send `Document` attachments. Extract document text in your application and include it in the prompt, or use a provider that supports document parts.

## Images
This integration does not send `Image` attachments. Use a provider that supports image parts when your prompt needs visual input.

## Considerations
### Message Order

Use `withSystemPrompt()` for Agent API instructions. User and assistant messages retain their order in `input`; a single user message is sent as a string. Tool-result messages have no client-tool continuation on this provider and are not sent.

### Additional fields
Read provider-specific fields from `$response->additionalContent`. Available keys include `search_results`, `fetch_url_results`, `resolved_model`, `response_id`, `response_status`, `annotations`, `usage` and `reasoning`. Citation annotations are in `annotations`; there is no top-level `citations` key. Check whether a key is present before reading it.

### Token Usage

`usage.input_tokens` maps to `promptTokens` and `usage.output_tokens` maps to `completionTokens`, with the older `prompt_tokens`/`completion_tokens` keys used as fallbacks. Cache and thought token fields remain `null`. `usage.cost.total_cost` maps to `Usage::$cost`, including a reported zero; absence leaves `cost` null. Read the full ledger from `additionalContent['usage']` rather than deriving a bill from token counts alone.

### Structured Output

Prism sends `response_format: json_schema` with your schema to the Agent API. Regex output constraints are not exposed by this integration.

Here's an example of how to use JSON Schema for structured output:

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
    ->using(Provider::Perplexity, 'sonar-pro')
    ->withPrompt('What\'s the weather like and what should I wear?')
    ->asStructured();
```
