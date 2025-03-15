# Embeddings

Transform your text into powerful vector representations! Embeddings let you add semantic search, recommendation systems, and other advanced natural language features to your applications.

## Quick Start

Here's how to generate an embedding with just a few lines of code:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Your text goes here')
    ->asEmbeddings();

// Get your embeddings vector
$embeddings = $response->embeddings[0]->embedding;

// Check token usage
echo $response->usage->tokens;
```

## Generating multiple embeddings

You can generate multiple embeddings at once with all providers that support embeddings, other than Gemini:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    // First embedding
    ->fromInput('Your text goes here')
    // Second embedding
    ->fromInput('Your second text goes here')
    // Third and fourth embeddings
    ->fromArray([
        'Third',
        'Fourth'
    ])
    ->asEmbeddings();

/** @var Embedding $embedding */
foreach ($embeddings as $embedding) {
    // Do something with your embeddings
    $embedding->embedding;
}

// Check token usage
echo $response->usage->tokens;
```

## Input Methods

You've got two convenient ways to feed text into the embeddings generator:

### Direct Text Input

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Analyze this text')
    ->asEmbeddings();
```

### From File

Need to analyze a larger document? No problem:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromFile('/path/to/your/document.txt')
    ->asEmbeddings();
```

> [!NOTE]
> Make sure your file exists and is readable. The generator will throw a helpful `PrismException` if there's any issue accessing the file.

## Common Settings

Just like with text generation, you can fine-tune your embeddings requests:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Your text here')
    ->withClientOptions(['timeout' => 30]) // Adjust request timeout
    ->withClientRetry(3, 100) // Add automatic retries
    ->asEmbeddings();
```

## Response Handling

The embeddings response gives you everything you need:

```php
namespace Prism\Prism\ValueObjects\Embedding;

// Get an array of Embedding value objects
$embeddings = $response->embeddings;

// Just get first embedding
$firstVectorSet = $embeddings[0]->embedding;

// Loop over all embeddings
/** @var Embedding $embedding */
foreach ($embeddings as $embedding) {
    $vectorSet = $embedding->embedding;
}

// Check token usage
$tokenCount = $response->usage->tokens;
```

## Error Handling

Always handle potential errors gracefully:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;

try {
    $response = Prism::embeddings()
        ->using(Provider::OpenAI, 'text-embedding-3-large')
        ->fromInput('Your text here')
        ->asEmbeddings();
} catch (PrismException $e) {
    Log::error('Embeddings generation failed:', [
        'error' => $e->getMessage()
    ]);
}
```

## Pro Tips 🌟

**Vector Storage**: Consider using a vector database like Milvus, Qdrant, or pgvector to store and query your embeddings efficiently.

**Text Preprocessing**: For best results, clean and normalize your text before generating embeddings. This might include:
   - Removing unnecessary whitespace
   - Converting to lowercase
   - Removing special characters
   - Handling Unicode normalization

> [!IMPORTANT]
> Different providers and models produce vectors of different dimensions. Always check your provider's documentation for specific details about the embedding model you're using.
