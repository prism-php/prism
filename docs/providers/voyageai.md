# Voyage AI
## Configuration

```php
'voyageai' => [
    'api_key' => env('VOYAGEAI_API_KEY', ''),
    'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
],
```

## Output dimensions

For text embeddings, set `outputDimension` through provider options to request a dimension supported by the selected model:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::embeddings()
    ->using('voyageai', 'voyage-4-large')
    ->fromInput('A document to index')
    ->withProviderOptions(['outputDimension' => 1024, 'inputType' => 'document'])
    ->asEmbeddings();
```

## Image embeddings

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::embeddings()
    ->using('voyageai', 'voyage-multimodal-3.5')
    ->fromInput('A text item to index')
    ->fromImage(Image::fromUrl('https://example.com/diagram.png'))
    ->withProviderOptions(['inputType' => 'document'])
    ->asEmbeddings();
```

Adding an image selects `/multimodalembeddings`. Each text input and each image becomes a separate input item, in that order; this API does not combine them into one interleaved item. Image URLs remain references, while local/raw/base64 images become data URIs. The multimodal handler forwards `inputType` and `truncation`, but does not forward `outputDimension`. Use a model available in the [Voyage multimodal catalog](https://docs.voyageai.com/docs/multimodal-embeddings).

Read vectors from `$response->embeddings`, token usage from `$response->usage`, and the model from `$response->meta`. Provider errors raise `PrismException`.

## Provider specific options

You can change some options on your request specific to Voyage AI by using `->withProviderOptions()`.

### Input type

By default, Voyage AI generates general purpose vectors.

Set the input type to `query` for search queries or `document` for content to be retrieved:

For search / querying:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::VoyageAI, 'voyage-3-lite')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['inputType' => 'query'])
    ->asEmbeddings();
```

For document retrieval:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::VoyageAI, 'voyage-3-lite')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['inputType' => 'document'])
    ->asEmbeddings();
```

### Truncation

By default, Voyage AI truncates inputs that are over the context length.

You can force it to throw an error instead by setting truncation to false.

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::VoyageAI, 'voyage-3-lite')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['truncation' => false])
    ->asEmbeddings();
```
