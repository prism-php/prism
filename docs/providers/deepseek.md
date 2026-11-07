# DeepSeek
## Configuration

```php
'deepseek' => [
    'api_key' => env('DEEPSEEK_API_KEY', ''),
    'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1')
]
```

## Provider-specific options

Everything you pass to `withProviderOptions()` is sent to DeepSeek in the request body, for text, structured and streaming requests. Prism's own settings, like `model`, `messages` and `stream`, always win over an option with the same name.

### Thinking mode

DeepSeek enables thinking mode by default. You can turn it off, or tune the reasoning effort, through provider options. See the [DeepSeek thinking mode guide](https://api-docs.deepseek.com/guides/thinking_mode) for details.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

// Disable thinking
$response = Prism::text()
    ->using(Provider::DeepSeek, 'deepseek-v4-pro')
    ->withPrompt('Who are you?')
    ->withProviderOptions(['thinking' => ['type' => 'disabled']])
    ->asText();

// Enable thinking with a specific effort (low, high or max)
$response = Prism::text()
    ->using(Provider::DeepSeek, 'deepseek-v4-pro')
    ->withPrompt('Solve this step by step...')
    ->withProviderOptions([
        'thinking' => ['type' => 'enabled'],
        'reasoning_effort' => 'max',
    ])
    ->asText();
```

When neither option is set, nothing is sent and DeepSeek's defaults apply.

## Streaming

DeepSeek supports streaming responses in real-time. All standard streaming methods are supported:

```php
return Prism::text()
    ->using('deepseek', 'deepseek-chat')
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

Does not support tool choice.

### Images

Does not support images.
