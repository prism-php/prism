# Voyage AI
## Configuration

```php
'voyageai' => [
    'api_key' => env('VOYAGEAI_API_KEY', ''),
    'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
],
```

## Provider specific options

You can change some options on your request specific to Voyage AI by using `->withProviderOptions()`.

### Input type

By default, Voyage AI generates general purpose vectors.

However, they taylor your vectors for the task they are intended for - for search ("query") or for retrieval ("document"):

For search / querying:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::embeddings()
    ->using(Provider::VoyageAI, 'voyage-3-lite')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['inputType' => 'query'])
    ->asEmbeddings();
```

For document retrieval:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

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
use Prism\Prism\Prism;

Prism::embeddings()
    ->using(Provider::VoyageAI, 'voyage-3-lite')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['truncation' => false])
    ->asEmbeddings();
```

## Rerank
VoyageAI provides a [reranker endpoint](https://docs.voyageai.com/reference/reranker-api) that receives as input a query, a list of documents, and other arguments such as the model name, and returns a response containing the reranking results.
This reranker endpoint can be used like this:

```php
use Prism\Prism\Providers\VoyageAI\VoyageAI;

VoyageAI::reranks(model: 'rerank-2-lite')
    ->withQuery('Sample query')
    ->withDocuments(['Sample document 1', 'Sample document 2'])
    ->withProviderOptions(['return_documents' => true])
    ->asRerank();
```
