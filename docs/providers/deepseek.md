# DeepSeek
## Configuration

```php
'deepseek' => [
    'api_key' => env('DEEPSEEK_API_KEY', ''),
    'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1')
]
```

## Text, tools and reasoning

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$tool = Tool::as('multiply')
    ->for('Multiply two numbers')
    ->withNumberParameter('left', 'First factor')
    ->withNumberParameter('right', 'Second factor')
    ->withProviderOptions(['strict' => true])
    ->using(fn (float $left, float $right): string => (string) ($left * $right));

$response = Prism::text()
    ->using('deepseek', 'deepseek-flash')
    ->withTools([$tool])
    ->withMaxSteps(3)
    ->withPrompt('What is 12 times 13?')
    ->asText();

echo $response->text;
$reasoning = $response->additionalContent['reasoning_content'] ?? null;
```

Per-tool `strict` is forwarded in the function definition. Request provider options such as `thinking` and `reasoning_effort` are passed through to DeepSeek; explicit Prism model, messages, token and sampling settings take precedence over conflicting options. Use options supported by the selected model. See the [DeepSeek model catalog](https://api-docs.deepseek.com/api/list-models/) for available IDs.

## Structured output

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->using('deepseek', 'deepseek-flash')
    ->withSchema(new ObjectSchema(
        'answer', 'An answer', [new StringSchema('text', 'Answer text')],
        requiredFields: ['text'],
    ))
    ->withPrompt('Describe a database index.')
    ->asStructured();
```

The structured handler uses `json_object` mode and adds schema instructions to a system message. It does not send a native JSON Schema constraint. Read decoded data from `$response->structured` and validate application-specific requirements.

## Errors

HTTP 402 raises `PrismException` with an `Insufficient Balance` message. Other failed HTTP requests include the provider's status and error details. See [Error Handling](/advanced/error-handling).

## Streaming

DeepSeek supports streaming responses in real-time. All standard streaming methods are supported:

```php
return Prism::text()
    ->using('deepseek', 'deepseek-flash')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Token Usage

DeepSeek's `prompt_tokens` is the sum of its cache hits and cache misses, so Prism splits the hits out to keep the input buckets apart (see [Token Usage](/core-concepts/text-generation#token-usage)). Text, structured and streamed responses all report usage like this:

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `prompt_tokens` minus `prompt_cache_hit_tokens`, so only the cache misses |
| `cacheReadInputTokens` | `prompt_cache_hit_tokens` (`prompt_tokens_details.cached_tokens` is the same number) |
| `cacheWriteInputTokens` | Always `null`, DeepSeek doesn't report cache writes |
| `completionTokens` | `completion_tokens`, reasoning included |
| `thoughtTokens` | `completion_tokens_details.reasoning_tokens`, already part of `completionTokens` |

When DeepSeek reports `0` cache hits, `cacheReadInputTokens` is `0` rather than `null`, so you can tell "no cache hit" apart from "not reported".

## Limitations
### Embeddings

Does not support embeddings.

### Tool Choice

Use `withToolChoice()` with `ToolChoice::Auto` or a tool name to control tool selection. This integration rejects `ToolChoice::Any` and `ToolChoice::None`.

### Images

The integration maps image parts into image URL or base64 content. Select a model that accepts image input; provider and model support determine whether the request succeeds.
