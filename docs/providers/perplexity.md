# Perplexity

> [!IMPORTANT]
> Prism uses Perplexity's Agent API (`POST /v1/agent`). Review the model mappings
> and request behavior below when migrating from Sonar.

## Migrating from Sonar

**Your model strings keep working.** Perplexity replaced model slugs with presets, and Prism
translates the retired slugs for you:

| You pass | Prism sends |
|---|---|
| `sonar` | `preset: fast` |
| `sonar-pro` | `preset: low` |
| `sonar-reasoning` | `preset: low` (slug retired upstream) |
| `sonar-reasoning-pro` | `preset: low` |
| `sonar-deep-research` | `preset: medium` |

A preset name given directly (`fast`, `low`, `medium`, `high`, `xhigh`, `wide-research`) is
passed through, and anything else is treated as a real model id — `openai/gpt-5.6-sol` is sent
as `model`, not guessed at as a preset.

### Overriding a preset

`sonar-deep-research` maps to `medium`. To select a different tier, set the preset explicitly:

```php
Prism::text()
    ->using(Provider::Perplexity, 'sonar-deep-research')
    ->withProviderOptions(['preset' => 'high'])
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

The breakdown Perplexity sends alongside the total — input, output and request components — stays
available on the raw response.

> [!NOTE]
> `tools: []` does not disable a preset's built-in tools. Perplexity exposes no public field
> that does.

## Configuration

```php
'perplexity' => [
    'api_key' => env('PERPLEXITY_API_KEY', ''),
    'url' => env('PERPLEXITY_URL', 'https://api.perplexity.ai'),
]
```

## Documents

Sonar models support document analysis through file uploads. You can provide files either as URLs to publicly accessible documents or as base64 encoded bytes. Ask questions about document content, get summaries, extract information, and perform detailed analysis of uploaded files in multiple formats including PDF, DOC, DOCX, TXT, and RTF.
- The maximum file size is 50MB. Files larger than this limit will not be processed
- Ensure provided HTTPS URLs are publicly accessible
Check it out the [documentation for more details](https://docs.perplexity.ai/guides/file-attachments)

## Images
Sonar models support image analysis through direct image uploads. You can include images in your API requests to support multi-modal conversations alongside text. Images can be provided either as base64 encoded strings within a data URI or as standard HTTPS URLs.
- When using base64 encoding, the API currently only supports images up to 50 MB per image
- Supported formats for base64 encoded images: PNG (image/png), JPEG (image/jpeg), WEBP (image/webp), and GIF (image/gif)
- When using an HTTPS URL, the model will attempt to fetch the image from the provided URL. Ensure the URL is publicly accessible.

## Considerations
### Message Order

- Message order matters. Perplexity is strict about the message order being:

1. `SystemMessage`
2. `UserMessage`
3. `AssistantMessage`

### Additional fields
Perplexity outputs additional fields in the response, such as `citations`, `search_results`, and the `reasoning` that is extracted from the model response. These fields are exposed in the response object
via the property `additionalFields`. e.g `$response->additionalFields['citations']`.

### Structured Output

Perplexity supports two types of structured outputs: JSON Schema and Regex; but currently Prism only supports JSON Schema.

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
