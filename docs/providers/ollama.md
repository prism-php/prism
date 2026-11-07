# Ollama
## Configuration

```php
'ollama' => [
    'url' => env('OLLAMA_URL', 'http://localhost:11434'),
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
  ]); // [!code focus]
```

> [!NOTE]
> Using `withProviderOptions` will override settings like `topP` and `temperature`

## Thinking, model lifetime and generation length

For text, structured and streaming requests, `withProviderOptions(['thinking' => false])` sets Ollama's top-level `think` value. `withReasoning(false)` also sets `think: false` unless `thinking` is explicitly provided. Select a model and Ollama version that support the chosen value.

`keep_alive` sets the top-level model lifetime. The integration also merges provider options into Ollama's `options` object. The default `num_predict` is 2048 when `withMaxTokens()` is absent; a provider option with the same name overrides it. The option named `thinking`, rather than `think`, controls the top-level field.

## Tools

Use `withTools([$tool])` and `withMaxSteps()` on a text request with a tool-capable model. Prism executes server tools, returns tool results to the model and supports the [approval controls](/core-concepts/human-in-the-loop). Explicit tool choice is unsupported.

## Embeddings

```php
use Prism\Prism\Facades\Prism;

$response = Prism::embeddings()
    ->using('ollama', 'nomic-embed-text')
    ->fromArray(['First document', 'Second document'])
    ->withProviderOptions(['keep_alive' => '5m'])
    ->asEmbeddings();

$vectors = $response->embeddings;
```

Pull the embedding model into your Ollama server before requesting it. `dimensions` and `keep_alive` are sent as top-level embedding fields; remaining provider options are sent in `options`. The model must support the requested dimensions. The response includes `usage`, `meta` and `raw`.

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
  ->withClientOptions(['timeout' => 60]); // [!code focus]
```

### Structured Output

`Prism::structured()` sends your schema in Ollama's `format` field. The response is decoded into `$response->structured`; malformed JSON raises a structured-decoding exception. Use a model that supports structured output.

## Limitations
### Image URL

Ollama does not support images using `Image::fromUrl()`.

### Tool Choice

Ollama does not currently support tool choice / required tools.
