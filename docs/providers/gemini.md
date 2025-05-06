# Gemini
## Configuration

```php
'gemini' => [
    'api_key' => env('GEMINI_API_KEY', ''),
    'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
],
```

## Search grounding

You may enable Google search grounding on text requests using providerOptions:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withPrompt('What is the stock price of Google right now?')
    // Enable search grounding
    ->withProviderOptions(['searchGrounding' => true])
    ->generate();
```

If you use search groundings, Google require you meet certain [display requirements](https://ai.google.dev/gemini-api/docs/grounding/search-suggestions).

The data you need to meet these display requirements, and to build e.g. footnote functionality will be saved to the response's `additionalContent` property.

```php
// The Google supplied and styled widget to click through to results.
$response->additionalContent['searchEntryPoint'];

// The search queries made by the model
$response->additionalContent['searchQueries'];

// The detail needed to build your citations
$response->additionalContent['groundingSupports'];
```

`groundingSupports` is an array of `MessagePartWithSearchGroundings`, which you can use to build up footnotes as follows:

```php
use Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings;
use Prism\Prism\Providers\Gemini\ValueObjects\SearchGrounding;

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithSearchGrounding $part */
foreach ($response->additionalContent['groundingSupports'] as $part) {
    $text .= $part->text;
    
    /** @var SearchGrounding $grounding */
    foreach ($part->groundings as $grounding) {
        $footnotes[] = [
            'id' => $footnoteId,
            'firstCharacter' => $part->startIndex,
            'lastCharacter' => $part->endIndex,
            'title' => $grounding->title,
            'uri' => $grounding->uri,
            'confidence' => $grounding->confidence // Float 0-1
        ];
    
        $text .= '<sup><a href="#footnote-'.$footnoteId.'">'.$footnoteId.'</a></sup>';
    
        $footnoteId++;
    }
}

// Pass $text and $footnotes to your frontend.
```

## Embeddings

You can customize your Gemini embeddings request with additional parameters using `->withProviderOptions()`.

### Title

You can add a title to your embedding request. Only applicable when TaskType is `RETRIEVAL_DOCUMENT`

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['title' => 'Restaurant Review'])
    ->asEmbeddings();
```

### Task Type

Gemini allows you to specify the task type for your embeddings to optimize them for specific use cases:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['taskType' => 'RETRIEVAL_QUERY'])
    ->asEmbeddings();
```
[Available task types](https://ai.google.dev/api/embeddings#tasktype)

### Output Dimensionality

You can control the dimensionality of your embeddings:

```php
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['outputDimensionality' => 768])
    ->asEmbeddings();
```

### Thinking Mode

Gemini 2.5 series models use an internal "thinking process" during response generation. Thinking is on by default as these models have the ability to automatically decide when and how much to think based on the prompt. If you would like to customize how many tokens the model may use for thinking, or disable thinking altogether, utilize the `withProviderOptions()` method, and pass through an array with a key value pair with `thinkingBudget` and an integer representing the budget of tokens. Set this value to `0` to disable thinking.

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash-preview')
    ->withPrompt('Explain the concept of Occam\'s Razor and provide a simple, everyday example.')
    // Set thinking budget
    ->withProviderOptions(['thinkingBudget' => 300])
    ->generate();
```
> [!NOTE]
> Do not specify a `thinkingBudget` on 2.0 or prior series Gemini models as your request will fail.
