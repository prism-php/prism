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
    ->using(Provider::Anthropic, 'claude-3-7-sonnet-latest')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Mistral]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Mistral, 'mistral-medium')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Ollama]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Ollama, 'llama2')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [OpenAI]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```

```php [Replicate]
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Replicate, 'meta/meta-llama-3.1-405b-instruct')
    ->withSystemPrompt(view('prompts.system'))
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();

echo $response->text;
```
:::

Prism's API is inspired by the [Vercel AI SDK](https://sdk.vercel.ai/docs/ai-sdk-core).

## Key Features

- **Unified Provider Interface**: Use a consistent request API across providers such as OpenAI, Anthropic and Ollama. Available features and options vary by provider.
- **Tool System**: Extend AI capabilities by defining custom tools that can interact with your application's business logic.
- **Image Support**: Work with multi-modal models that can process both text and images.

Prism also provides a fluent `prism()` helper function to resolve the `Prism` instance from the application container.

```php
prism()
    ->text()
    ->using(Provider::OpenAI, 'gpt-4')
    ->withPrompt('Explain quantum computing to a 5-year-old.')
    ->asText();
``` 

## Providers

Prism includes integrations for the following providers:

- [Anthropic](/providers/anthropic.md)
- Azure OpenAI (`Provider::Azure`)
- [DeepSeek](/providers/deepseek.md)
- [ElevenLabs](/providers/elevenlabs.md)
- [Gemini](/providers/gemini.md)
- [Groq](/providers/groq.md)
- [Mistral](/providers/mistral.md)
- [Ollama](/providers/ollama.md)
- [OpenAI](/providers/openai.md)
- [OpenRouter](/providers/openrouter.md)
- [Requesty](/providers/requesty.md)
- [Replicate](/providers/replicate.md)
- [Qwen](/providers/qwen.md)
- [xAI](/providers/xai.md)
- [Perplexity](/providers/perplexity.md)
- [Vertex AI](/providers/vertex.md)
- [VoyageAI](/providers/voyageai.md)
- [Z](/providers/z.md)

## Provider Support

See each provider's page for supported features, configuration and limitations. Availability also depends on the selected model.

<ProviderSupport />
