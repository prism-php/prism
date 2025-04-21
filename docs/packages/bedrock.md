# Prism Bedrock

Unlock the power of AWS Bedrock services in your Laravel applications with Prism Bedrock. This package provides a standalone Bedrock provider for the Prism PHP framework.

## Installation

```bash
composer require prism-php/bedrock
```

## Configuration

Add the following to your Prism configuration (`config/prism.php`):

```php
'bedrock' => [ // Key should match Bedrock::KEY
    'api_key' => env('AWS_ACCESS_KEY_ID'),
    'api_secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_REGION', 'us-east-1')
],
```

## Usage

### Text Generation

```php
use Prism\Prism\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

echo $response->text;
```

### Structured Output (JSON)

```php
use Prism\Prism\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\ArraySchema;

$schema = new ObjectSchema(
    name: 'languages',
    description: 'Top programming languages',
    properties: [
        new ArraySchema(
            'languages',
            'List of programming languages',
            items: new ObjectSchema(
                name: 'language',
                description: 'Programming language details',
                properties: [
                    new StringSchema('name', 'The language name'),
                    new StringSchema('popularity', 'Popularity description'),
                ]
            )
        )
    ]
);

$response = Prism::structured()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withSchema($schema)
    ->withPrompt('List the top 3 programming languages')
    ->asStructured();

// Access your structured data
$data = $response->structured;
```

### Embeddings (with Cohere models)

```php
use Prism\Prism\Prism;
use Prism\Bedrock\Bedrock;

$response = Prism::embeddings()
    ->using(Bedrock::KEY, 'cohere.embed-english-v3')
    ->fromArray(['The sky is blue', 'Water is wet'])
    ->asEmbeddings();

// Access the embeddings
$embeddings = $response->embeddings;
```

## Supported API Schemas

AWS Bedrock supports several API schemas - some LLM vendor specific, some generic.

Prism Bedrock supports three of those API schemas:

- **Converse**: AWS's native interface for chat (default)*
- **Anthropic**: For Claude models**
- **Cohere**: For Cohere embeddings models

Each schema supports different capabilities:

| Schema | Text | Structured | Embeddings |
|--------|:----:|:----------:|:----------:|
| Converse | ✅ | ✅ | ❌ |
| Anthropic | ✅ | ✅ | ❌ |
| Cohere | ❌ | ❌ | ✅ |

\* A unified interface for multiple providers. See [AWS documentation](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-supported-models-features.html) for a list of supported models.

\*\* The Converse schema does not support Anthropic specific features. This schema uses Anthropic's native schema and therefore allows use of Anthropic features. Please note however that AWS does not yet have feature parity with Anthropic. Notably Bedrock does not support documents or citations, and only supports prompt caching for Claude Sonnet 3.7 and Claude Haiku 3.5.

## Auto-resolution of API schemas

Prism Bedrock resolves the most appropriate API schema from the model string. If it is unable to resolve a specific schema (e.g. anthropic for Anthropic), it will default to Converse.

Therefore if you use a model that is not supported by AWS Bedrock Converse, and does not have a specific Prism Bedrock implementation, your request will fail.

If you wish to force Prism Bedrock to use Converse instead of a vendor specific interface, you can do so with `withProviderMeta()`:

```php
use Prism\Prism\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Bedrock\Enums\BedrockSchema;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withProviderMeta(Bedrock::KEY, ['apiSchema' => BedrockSchema::Converse])
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();

```

## Cache-Optimized Prompts

For [supported models](https://docs.aws.amazon.com/bedrock/latest/userguide/prompt-caching.html), you can enable prompt caching to reduce latency and costs:

```php
use Prism\Prism\Prism;
use Prism\Bedrock\Bedrock;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using(Bedrock::KEY, 'anthropic.claude-3-sonnet-20240229-v1:0')
    ->withMessages([
        (new UserMessage('Message with cache breakpoint'))->withProviderMeta('bedrock', ['cacheType' => 'ephemeral']),
        (new UserMessage('Message with another cache breakpoint'))->withProviderMeta('bedrock', ['cacheType' => 'ephemeral']),
        new UserMessage('Compare the last two messages.')
    ])
    ->asText();
```

> [!TIP]
> Anthropic currently supports a cacheType of "ephemeral". Converse currently supports a cacheType of "default". It is possible that Anthropic and/or AWS may add additional types in the future.