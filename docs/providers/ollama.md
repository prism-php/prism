# Ollama
## Configuration

```php
'ollama' => [
    'url' => env('OLLAMA_URL', 'http://localhost:11434/v1'),
],
```

## Ollama Options

Ollama allows you to customize how the model is run via [options](https://github.com/ollama/ollama/blob/main/docs/modelfile.md#parameter). These options can be passed via the `->withProviderOptions()` method.

```php
Prism::text() // [!code focus]
  ->using(Provider::Ollama, 'gemma3:1b')
  ->withPrompt('Who are you?')
  ->withClientOptions(['timeout' => 60])
  ->withProviderOptions([ // [!code focus]
      'top_p' => 0.9, // [!code focus]
      'num_ctx' => 4096, // [!code focus]
  ]) // [!code focus]
```

> [!NOTE]
> Using `withProviderOptions` will override settings like `topP` and `temperature`

## Streaming

Ollama supports streaming responses from your local models. All standard streaming methods are supported:

```php
return Prism::text()
    ->using('ollama', 'llama3.2')
    ->withPrompt(request('message'))
    ->withClientOptions(['timeout' => 120])
    ->asEventStreamResponse();
```

> [!TIP]
> Remember to increase the timeout for local models to prevent premature disconnection.

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Token Usage

Ollama's `prompt_eval_count` is the whole prompt, cached tokens included. From Ollama 0.33.3, responses also report `prompt_eval_cached_count`, and Prism uses it to split the cached part out (see [Token Usage](/core-concepts/text-generation#token-usage)):

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `prompt_eval_count` minus `prompt_eval_cached_count` |
| `cacheReadInputTokens` | `prompt_eval_cached_count`, `null` on servers older than 0.33.3 |
| `cacheWriteInputTokens` | Always `null`, Ollama doesn't report cache writes |
| `completionTokens` | `eval_count` |
| `thoughtTokens` | Always `null`, Ollama doesn't break out thinking tokens |

On older servers `promptTokens` is the whole prompt, since there's nothing to split out. Ollama's docs don't say whether `eval_count` includes thinking, but its maintainers have said it does, so treat `completionTokens` as covering both. In a stream, the counts arrive on the final chunk of each response. The `StreamEndEvent` adds up every step of a tool loop, and each `StepFinishEvent` carries its own step's usage.

## Considerations
### Timeouts

Depending on your configuration, responses tend to time out. You may need to extend the client's timeout using `->withClientOptions(['timeout' => $seconds])`.

```php
Prism::text() // [!code focus]
  ->using(Provider::Ollama, 'gemma3:1b')
  ->withPrompt('Who are you?')
  ->withClientOptions(['timeout' => 60]) // [!code focus]
```

### Structured Output

Ollama doesn't have native JSON mode or structured output like some providers, Prism implements a robust workaround for structured output:

- We automatically append instructions to your prompt that guide the model to output valid JSON matching your schema
- If the response isn't valid JSON, Prism will raise a PrismException

## Limitations
### Image URL

Ollama does not support images using `Image::fromUrl()`.

### Tool Choice

Ollama does not currently support tool choice / required tools.
