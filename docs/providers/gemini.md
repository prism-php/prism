# Gemini
## Configuration

```php
'gemini' => [
    'api_key' => env('GEMINI_API_KEY', ''),
    'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
],
```

## Search grounding

Google Gemini offers built-in search grounding capabilities that allow your AI to search the web for real-time information. This is a provider tool that uses Google's search infrastructure. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

You may enable Google search grounding on text requests using withProviderTools:

```php
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\ProviderTool;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withPrompt('What is the stock price of Google right now?')
    // Enable search grounding
    ->withProviderTools([
            new ProviderTool('google_search')
        ])
    ->asText();
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

## Caching

Prism supports Gemini prompt caching, though due to Gemini requiring you first upload the cached content, it works a little differently to other providers. 

To store content in the cache, use the Gemini provider cache method as follows:

```php

use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Providers\Gemini\Gemini;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/** @var Gemini */
$provider = Prism::provider(Provider::Gemini);

$object = $provider->cache(
    model: 'gemini-1.5-flash-002',
    messages: [
        new UserMessage('', [
            Document::fromLocalPath('tests/Fixtures/long-document.pdf'),
        ]),
    ],
    systemPrompts: [
        new SystemMessage('You are a legal analyst.'),
    ],
    ttl: 60
);
```

Then reference that object's name in your request using withProviderOptions:

```php
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash-002')
    ->withProviderOptions(['cachedContentName' => $object->name])
    ->withPrompt('In no more than 100 words, what is the document about?')
    ->asText();
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
    ->asText();
```
> [!NOTE]
> Do not specify a `thinkingBudget` on 2.0 or prior series Gemini models as your request will fail.

## Media Support

Gemini has robust support for processing multimedia content:

### Video Analysis

Gemini can process and analyze video content including standard video files and YouTube videos. Prism implements this through the `Video` value object which maps to Gemini's video processing capabilities.

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'What is happening in this video?',
            additionalContent: [
                Video::fromUrl('https://example.com/sample-video.mp4'),
            ],
        ),
    ])
    ->asText();
```

### YouTube Integration

Gemini has special support for YouTube videos. You can easily `analyze/summarize` YouTube content by providing the URL:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'Summarize this YouTube video:',
            additionalContent: [
                Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
            ],
        ),
    ])
    ->asText();
```
### Audio Processing

Gemini can analyze audio files for various tasks like transcription, content analysis, and audio scene understanding. The implementation in Prism uses the `Audio` value object which is specifically designed for Gemini's audio processing capabilities.

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'Transcribe this audio file:',
            additionalContent: [
                Audio::fromLocalPath('/path/to/audio.mp3'),
            ],
        ),
    ])
    ->asText();
```

