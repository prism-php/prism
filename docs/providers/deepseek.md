# DeepSeek
## Configuration

```php
'deepseek' => [
    'api_key' => env('DEEPSEEK_API_KEY', ''),
    'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1')
]
```

## Provider-specific options

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
