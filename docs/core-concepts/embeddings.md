# Embeddings

Transform your content into powerful vector representations! Embeddings let you add semantic search, recommendation systems, and other advanced features to your applications - whether you're working with text or images.

## Quick Start

Here's how to generate an embedding with just a few lines of code:

```php
use Prism\Prism\Facades\Prism;
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
use Prism\Prism\Facades\Prism;
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
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromInput('Analyze this text')
    ->asEmbeddings();
```

### From File

Need to analyze a larger document? No problem:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::OpenAI, 'text-embedding-3-large')
    ->fromFile('/path/to/your/document.txt')
    ->asEmbeddings();
```

> [!NOTE]
> Make sure your file exists and is readable. The generator will throw a helpful `PrismException` if there's any issue accessing the file.

## Image Embeddings

Some providers support image embeddings, enabling powerful use cases like visual similarity search, cross-modal retrieval, and multimodal applications. Prism makes it easy to generate embeddings from images using the same fluent API.

> [!IMPORTANT]
> Image embeddings require a provider and model that supports image input (such as CLIP-based models or multimodal embedding models like BGE-VL). Check your provider's documentation to confirm image embedding support.

### Single Image

Generate an embedding from a single image:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::embeddings()
    ->using('provider', 'model')
    ->fromImage(Image::fromLocalPath('/path/to/product.jpg'))
    ->asEmbeddings();

$embedding = $response->embeddings[0]->embedding;
```

### Multiple Images

Process multiple images in a single request:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::embeddings()
    ->using('provider', 'model')
    ->fromImages([
        Image::fromLocalPath('/path/to/image1.jpg'),
        Image::fromUrl('https://example.com/image2.png'),
    ])
    ->asEmbeddings();

foreach ($response->embeddings as $embedding) {
    // Process each image embedding
    $vector = $embedding->embedding;
}
```

### Multimodal: Text + Image

Combine text and images for cross-modal search scenarios. This is particularly useful for applications like "find products similar to this image that match this description":

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::embeddings()
    ->using('provider', 'model')
    ->fromInput('Find similar products in red')
    ->fromImage(Image::fromBase64($productImage, 'image/png'))
    ->asEmbeddings();
```

You can chain `fromImage()` and `fromInput()` in any order - Prism handles both gracefully.

> [!TIP]
> The `Image` class supports multiple input sources: `fromLocalPath()`, `fromUrl()`, `fromBase64()`, `fromStoragePath()`, and `fromRawContent()`. See the [Images documentation](/input-modalities/images.html) for details.

## Common Settings

Just like with text generation, you can fine-tune your embeddings requests:

```php
use Prism\Prism\Facades\Prism;
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
use Prism\Prism\Facades\Prism;
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

## Pro Tips

**Vector Storage**: Consider using a vector database like Milvus, Qdrant, or pgvector to store and query your embeddings efficiently.

**Text Preprocessing**: For best results, clean and normalize your text before generating embeddings. This might include:
   - Removing unnecessary whitespace
   - Converting to lowercase
   - Removing special characters
   - Handling Unicode normalization

> [!IMPORTANT]
> Different providers and models produce vectors of different dimensions. Always check your provider's documentation for specific details about the embedding model you're using.
