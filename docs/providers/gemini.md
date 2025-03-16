# Gemini
## Configuration

```php
'gemini' => [
    'api_key' => env('GEMINI_API_KEY', ''),
    'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
],
```

## Search grounding

You may enable Google search grounding on text requests using providerMeta:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withPrompt('What is the stock price of Google right now?')
    // Enable search grounding
    ->withProviderMeta(Provider::Gemini, ['searchGrounding' => true])
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