# Embeddings

Transform your content into powerful vector representations! Embeddings let you add semantic search, recommendation systems, and other advanced features to your applications - whether you're working with text, images, audio, video, or documents.

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

You can generate multiple embeddings at once with providers that support batch embeddings:

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

## Multimodal Embeddings

Some providers support multimodal embeddings, enabling powerful use cases like visual similarity search, cross-modal retrieval, and mixed media retrieval. Prism makes it easy to generate embeddings from images, audio, video, and documents using the same fluent API.

> [!IMPORTANT]
> Multimodal embeddings require a provider and model that supports the input modalities you send. Check your provider's documentation to confirm support for images, audio, video, documents, and grouped content.

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

### Audio, Video, and Documents

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Video;

$response = Prism::embeddings()
    ->using('provider', 'model')
    ->fromAudio(Audio::fromLocalPath('/path/to/sample.mp3'))
    ->fromVideo(Video::fromLocalPath('/path/to/sample.mp4'))
    ->fromDocument(Document::fromLocalPath('/path/to/report.pdf'))
    ->asEmbeddings();
```

### Grouped Multimodal Content

Use `fromContent()` when you want a single embedding generated from multiple parts within the same content entry:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::embeddings()
    ->using('provider', 'model')
    ->fromContent([
        'Find similar products in red',
        Image::fromBase64($productImage, 'image/png'),
    ])
    ->asEmbeddings();
```

Use `fromContents()` when you want multiple embeddings in a single request:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::embeddings()
    ->using('provider', 'model')
    ->fromContents([
        ['The dog is cute'],
        [Image::fromLocalPath('/path/to/dog.png')],
    ])
    ->asEmbeddings();
```

You can still chain `fromInput()` and `fromImage()` in any order. Each chained call creates a separate content entry.

> [!TIP]
> Prism media value objects support multiple input sources. See the [Images documentation](/input-modalities/images.html) and related modality guides for details.

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
