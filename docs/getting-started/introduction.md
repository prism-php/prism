<script setup>
import ProviderSupport from '../components/ProviderSupport.vue'
</script>

# Introduction

Prism provides a unified PHP interface for language-model APIs in Laravel applications.
Use it to generate text, stream responses, call tools and work with multimodal input.

Generate text by selecting a provider and model:

::: code-group
```php [Anthropic]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-5-5')
    ->withSystemPrompt('Explain concepts in plain language.')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Mistral]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Mistral, 'mistral-medium-3-5')
    ->withSystemPrompt('Explain concepts in plain language.')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Ollama]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Ollama, 'llama3.2')
    ->withSystemPrompt('Explain concepts in plain language.')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [OpenAI]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4.1')
    ->withSystemPrompt('Explain concepts in plain language.')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Replicate]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Replicate, 'meta/meta-llama-3.1-405b-instruct')
    ->withSystemPrompt('Explain concepts in plain language.')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```
:::

Prism's API is inspired by the [Vercel AI SDK](https://sdk.vercel.ai/docs/ai-sdk-core).

## Key Features

- [Text and conversations](/core-concepts/text-generation), [structured output](/core-concepts/structured-output), and [streaming](/core-concepts/streaming-output).
- [Custom and provider tools](/core-concepts/tools-function-calling), including [client execution and approvals](/core-concepts/human-in-the-loop) on supported integrations.
- [Embeddings](/core-concepts/embeddings), [image generation/editing](/core-concepts/image-generation), [audio processing](/core-concepts/audio), and [moderation](/core-concepts/moderation).
- [Images](/input-modalities/images), [documents](/input-modalities/documents), [audio](/input-modalities/audio) and [video](/input-modalities/video) as model inputs.
- [Files](/core-concepts/files), [Batch](/core-concepts/batch), and [Mistral fill-in-the-middle](/providers/mistral#fill-in-the-middle).
- [Response fakes](/core-concepts/testing), [telemetry events](/advanced/telemetry), and an optional [OpenAI-compatible server](/core-concepts/prism-server).

Support varies by provider and model; a shared method does not imply that every integration supports it. The Ollama example requires that the named model is installed on your server.

Prism also provides a fluent `prism()` helper function to resolve the `Prism` instance from the application container.

```php
prism()
    ->text()
    ->using(Provider::OpenAI, 'gpt-4.1')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();
``` 

## Providers

Prism includes integrations for the following providers:

- [Anthropic](/providers/anthropic.md)
- [Azure OpenAI](/providers/azure.md)
- [DeepSeek](/providers/deepseek.md)
- [ElevenLabs](/providers/elevenlabs.md)
- [Gemini](/providers/gemini.md)
- [Groq](/providers/groq.md)
- [Mistral](/providers/mistral.md)
- [Ollama](/providers/ollama.md)
- [OpenAI](/providers/openai.md)
- [OpenRouter](/providers/openrouter.md)
- [Perplexity](/providers/perplexity.md)
- [Qwen](/providers/qwen.md)
- [Replicate](/providers/replicate.md)
- [Requesty](/providers/requesty.md)
- [Vertex AI](/providers/vertex.md)
- [VoyageAI](/providers/voyageai.md)
- [xAI](/providers/xai.md)
- [Z](/providers/z.md)

## Provider Support

See each provider's page for supported features, configuration and limitations. Availability also depends on the selected model.

<ProviderSupport />
