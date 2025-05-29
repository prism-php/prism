# Fireworks AI

## Configuration

```php
'fireworksai' => [
    'api_key' => env('FIREWORKS_API_KEY', ''),
    'url' => env('FIREWORKS_URL', 'https://api.fireworks.ai/inference/v1'),
],
```

## Provider-specific Settings

The primary provider-specific settings are configured during the initial setup (see Configuration above).
* **Model Naming**: Remember that model names for Fireworks AI typically include account prefixes, for example: `accounts/fireworks/models/llama-v3-8b-instruct`.

## Considerations

* **Structured Output Approach**: When using `Prism::structured()`, you must explicitly choose between Fireworks AI's three structured output approaches: JSON mode, grammar mode, or function calling. Each method requires specific configuration:
    * **JSON mode**: Set `response_format: ['type' => 'json_object']`. If a schema is provided to Prism, it will be included in the `response_format` sent to Fireworks AI.
    * **Grammar mode**: Provide a `grammar` parameter within `withProviderOptions()` containing your GBNF string.
    * **Function calling**: Define tools/functions in your request and structure your prompt to guide the model towards using them.
    * **Note**: Fireworks AI does not automatically fall back between these structured output methods; explicit configuration for the desired approach is required.
* **Image and Document Handling**: The provider supports the inlining of image data (both Base64 encoded strings and URLs) and document text directly within user messages. This enables multimodal interactions with compatible Fireworks AI models.
    * **Note**: Document inlining with Fireworks AI is a feature that may be in public preview and its terms of use (e.g., pricing) could change. Always refer to the official Fireworks AI documentation for the latest status.
* **Rate Limits**: The client is designed to automatically process `x-ratelimit-*` headers returned by the Fireworks AI API. This information is used to populate rate limit details, which can be particularly useful for handling `PrismRateLimitedException`.

## Provider-specific options

### Grammar-Constrained Output

You can enforce output structure using a GBNF (Grammar-Based BNF) grammar. This is applicable for text generation, streaming, and structured requests to ensure the model's output conforms to a predefined format.

```php
$response = Prism::structured() // Also applicable to text() or stream()
    ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3-8b-instruct')
    ->withPrompt("Generate a JSON list of three fruits.")
    ->withProviderOptions([ // [!code focus]
        'grammar' => 'root ::= "fruit_list" "\\n" ("- " [a-zA-Z]+ "\\n")*' // Replace with your actual GBNF grammar string [!code focus]
    ]) // [!code focus]
    ->asStructured();
```

### "Any" Tool Choice

Fireworks AI accommodates a specific `tool_choice` option of `'any'`. When this is set, the model is compelled to select and use one of the tools you have provided in the request. This option works particularly well with Fireworks AI's FireFunction series of models.

```php
$response = Prism::text()
    ->using(Provider::FireworksAI, 'accounts/fireworks/models/firefunction-v1') // Example FireFunction model
    ->withTools([/* ...your defined tools... */])
    ->withProviderOptions([ // [!code focus]
        'tool_choice' => 'any' // [!code focus]
    ]) // [!code focus]
    ->asText();
```
This is an extension to standard behaviors like `'auto'` (model decides), `'none'` (no tools called), or specifying a particular tool's name.

### Additional Generation Controls

Fireworks AI offers several other parameters to fine-tune the generation process. These can be passed using the `withProviderOptions()` method:

```php
$response = Prism::text()
    ->using(Provider::FireworksAI, 'accounts/fireworks/models/llama-v3-8b-instruct')
    ->withPrompt('Tell me an interesting fact.')
    ->withProviderOptions([ // [!code focus]
        'repetition_penalty' => 1.15, // [!code focus]
        'context_length_exceeded_behavior' => 'truncate', // Example: 'truncate' or other supported values [!code focus]
        // Other available options include: mirostat_lr, mirostat_target, raw_output, echo [!code focus]
    ]) // [!code focus]
    ->asText();
```
Common parameters such as `temperature`, `top_p`, and `max_tokens` are also configurable via `withProviderOptions` or their respective dedicated helper methods.

### Embedding Dimensions

When requesting embeddings, you can specify the desired `dimensions` for the output vectors, provided the selected Fireworks AI embedding model supports this customization.

```php
$response = Prism::embeddings()
    ->using(Provider::FireworksAI, 'accounts/fireworks/models/nomic-embed-text-v1.5') // Example embedding model
    ->fromInput('Text to be embedded.')
    ->withProviderOptions(['dimensions' => 768]) // [!code focus]
    ->asEmbeddings();
```

## Limitations

* At present, no specific limitations unique to the Fireworks AI provider (beyond general API usage patterns or those inherent to the underlying models) are explicitly noted within the Prism integration. The provider aims to expose core Fireworks AI functionalities like tool use, streaming, and structured output.