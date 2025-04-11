# Ollama
## Configuration

```php
'ollama' => [
    'url' => env('OLLAMA_URL', 'http://localhost:11434/v1'),
],
```
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
