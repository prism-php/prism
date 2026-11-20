# Azure

Use Azure OpenAI deployments for text, streaming, structured output, embeddings and image generation. Enable each capability on a compatible deployment in your Azure account.

## Configuration

Set these server-side environment variables:

```dotenv
AZURE_AI_URL=https://your-resource.openai.azure.com
AZURE_AI_API_KEY=your-api-key
AZURE_AI_API_VERSION=2024-10-21
AZURE_AI_DEPLOYMENT=your-chat-deployment
```

The corresponding `prism.providers.azure` settings are `url`, `api_key`, `api_version` and `deployment_name`. Requests use the `api-key` header. Keep the endpoint and credentials in application configuration.

For deployment-based URLs, Prism appends `/openai/deployments/{deployment}` and sends `api-version` as a query parameter. `deployment_name` takes precedence over the model passed to `using()`; an empty deployment setting uses that model as the deployment name.

Prism uses `/openai/v1` when the configured URL contains that path, `api_version` is `v1`, or the requested model name contains `gpt-5`. In this mode it sends the resolved deployment in the `model` field and omits the API-version query parameter. Use the base resource URL or the `/openai/v1` base, rather than an operation-specific URL. See [Azure's endpoint documentation](https://learn.microsoft.com/en-us/azure/ai-foundry/openai/api-version-lifecycle).

## Text and tools

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('azure', 'your-chat-deployment')
    ->withPrompt('Explain the purpose of a database index.')
    ->withMaxTokens(500)
    ->asText();

echo $response->text;
```

Text requests support [custom tools](/core-concepts/tools-function-calling), tool choice and [approval-required or client-executed tools](/core-concepts/human-in-the-loop). Set strict tool schemas with `withProviderOptions(['strict' => true])` on the tool. Select a deployment that supports these features.

For text, structured and streaming requests, the integration forwards these request options:

| Option | Mapping |
|---|---|
| `reasoning_effort` or `reasoning.effort` | `reasoning_effort`; the first form takes precedence |
| `verbosity` or `text_verbosity` | `verbosity`; the first form takes precedence |

Use `withMaxTokens()` for the generation limit. Model names containing `gpt-5` use `max_completion_tokens`; other names use `max_tokens`.

## Structured output

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->using('azure', 'your-chat-deployment')
    ->withSchema(new ObjectSchema(
        'summary', 'A document summary',
        [new StringSchema('text', 'Summary text')],
        requiredFields: ['text'],
    ))
    ->withPrompt('Summarize what an index does.')
    ->asStructured();

echo $response->structured['text'];
```

Azure structured requests use `response_format: json_object` with schema instructions in a system message. This integration does not send a native JSON Schema constraint or execute tools on the structured path. Validate application-specific constraints after decoding.

## Streaming

Call `asStream()`, `asEventStreamResponse()` or `asDataStreamResponse()` on a text request. Approval-required tools emit a `ToolApprovalRequestEvent` and pause without executing; resume using the decisions described in [Human-in-the-Loop](/core-concepts/human-in-the-loop).

::: warning Testing v1 streams
The Azure v1 streaming path uses a native PHP HTTP stream with a 300-second timeout. It bypasses Laravel `Http::fake()` and `preventStrayRequests()`, as well as the configured Laravel HTTP retry and client options. Do not run this path with real credentials in tests expecting those fakes to prevent network requests.
:::

## Embeddings and images

Override `deployment_name` when a request needs a different deployment:

```php
use Prism\Prism\Facades\Prism;

$embeddings = Prism::embeddings()
    ->using('azure', 'your-embedding-deployment', [
        'deployment_name' => 'your-embedding-deployment',
    ])
    ->fromInput('A document to index')
    ->asEmbeddings();

$images = Prism::image()
    ->using('azure', 'your-image-deployment', [
        'deployment_name' => 'your-image-deployment',
    ])
    ->withPrompt('A blue notebook on a wooden desk')
    ->withProviderOptions(['n' => 1, 'size' => '1024x1024'])
    ->generate();
```

Embedding provider options are included in the embedding payload. Image options include `n`, `size`, `response_format`, `quality`, `style` and `output_format`; other image provider options are also forwarded. The selected Azure deployment must accept the supplied options. See [Embeddings](/core-concepts/embeddings) and [Image Generation](/core-concepts/image-generation) for response handling.

## Errors and unsupported operations

Text/structured request failures map HTTP 429 to `PrismRateLimitedException`, 413 to `PrismRequestTooLargeException`, and 400/404 to `PrismException` with the Azure error message. Other failures use the provider request exception. Handle image errors through `PrismException`.

This provider does not implement audio, moderation, files, batches or fill-in-the-middle requests. Use a provider that supports the required operation.
