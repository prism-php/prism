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
use Prism\Prism\Facades\Prism;
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

// The citations data is available as an array of MessagePartWithCitations
$response->additionalContent['citations'];
```

`citations` is an array of `MessagePartWithCitations`, which you can use to build up footnotes as follows:

```php
use Prism\Prism\ValueObjects\MessagePartWithCitations;
use Prism\Prism\ValueObjects\Citation;

$text = '';
$footnotes = [];

$footnoteId = 1;

/** @var MessagePartWithCitations $part */
foreach ($response->additionalContent['citations'] as $part) {
    $text .= $part->outputText;
    
    /** @var Citation $citation */
    foreach ($part->citations as $citation) {
        $footnotes[] = [
            'id' => $footnoteId,
            'title' => $citation->sourceTitle,
            'uri' => $citation->source,
        ];

        $text .= '<sup><a href="#footnote-'.$footnoteId.'">'.$footnoteId.'</a></sup>';

        $footnoteId++;
    }
}

// Pass $text and $footnotes to your frontend.
```

## Structured Output

Gemini supports structured output, allowing you to define schemas that constrain the model's responses to match your exact data structure requirements.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    name: 'movie_review',
    description: 'A structured movie review',
    properties: [
        new StringSchema('title', 'The movie title'),
        new StringSchema('rating', 'Rating out of 5 stars'),
        new StringSchema('summary', 'Brief review summary'),
    ],
    requiredFields: ['title', 'rating', 'summary']
);

$response = Prism::structured()
    ->using(Provider::Gemini, 'gemini-2.0-flash')
    ->withSchema($schema)
    ->withPrompt('Review the movie Inception')
    ->asStructured();

// Access structured data
dump($response->structured);
```

### Flexible Types with anyOf

For fields that can match multiple types or structures, use `AnyOfSchema`. This is useful for polymorphic data or when a field might contain different shapes:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

// Simple example: value can be string or number
$schema = new ObjectSchema(
    'response',
    'API response with flexible value',
    [
        new AnyOfSchema(
            schemas: [
                new StringSchema('text', 'Text value'),
                new NumberSchema('number', 'Numeric value'),
            ],
            name: 'value',
            description: 'Can be either text or number'
        ),
    ],
    ['value']
);

$response = Prism::structured()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withSchema($schema)
    ->withPrompt('Extract the value from: "The answer is 42"')
    ->asStructured();

// $response->structured['value'] could be "42" (string) or 42 (number)
```

For complex polymorphic structures, `anyOf` can distinguish between entirely different object types:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$articleSchema = new ObjectSchema(
    'article',
    'A blog article',
    [
        new StringSchema('title', 'Article title'),
        new StringSchema('content', 'Full article text'),
        new StringSchema('author', 'Author name'),
    ],
    ['title', 'content']
);

$imageSchema = new ObjectSchema(
    'image',
    'An image post',
    [
        new StringSchema('url', 'Image URL'),
        new StringSchema('caption', 'Image caption'),
        new NumberSchema('width', 'Width in pixels'),
        new NumberSchema('height', 'Height in pixels'),
    ],
    ['url']
);

$schema = new ObjectSchema(
    'social_post',
    'Social media post',
    [
        new AnyOfSchema(
            schemas: [$articleSchema, $imageSchema],
            name: 'content',
            description: 'Post content - either article or image'
        ),
    ],
    ['content']
);

$response = Prism::structured()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withSchema($schema)
    ->withPrompt('Analyze this post and extract its content')
    ->asStructured();

// Result will be either {title, content, author} OR {url, caption, width, height}
```

> [!NOTE]
> The `anyOf` feature requires Gemini 2.5 or later models.

### Numeric Constraints

Constrain numeric values to specific ranges and precision using JSON Schema numeric constraints:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    'product_rating',
    'Product rating information',
    [
        new NumberSchema(
            name: 'rating',
            description: 'User rating (1-5 stars, half-star increments)',
            minimum: 1.0,
            maximum: 5.0,
            multipleOf: 0.5
        ),
        new NumberSchema(
            name: 'price',
            description: 'Product price in USD',
            minimum: 0.01,
            exclusiveMaximum: 10000.0
        ),
        new NumberSchema(
            name: 'quantity',
            description: 'Stock quantity',
            minimum: 0
        ),
    ],
    ['rating', 'price', 'quantity']
);

$response = Prism::structured()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withSchema($schema)
    ->withPrompt('Extract rating, price, and quantity from this product review')
    ->asStructured();
```

**Available Numeric Constraints:**
- `minimum` - Minimum value (inclusive)
- `maximum` - Maximum value (inclusive)
- `exclusiveMinimum` - Minimum value (exclusive)
- `exclusiveMaximum` - Maximum value (exclusive)
- `multipleOf` - Value must be a multiple of this number

### Nullable Fields

Make any field optional by marking it as nullable. The field must be present in the response, but can be `null`:

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$schema = new ObjectSchema(
    'user',
    'User profile',
    [
        new StringSchema('name', 'User name'),
        new StringSchema('email', 'Email address', nullable: true),  // Optional
    ],
    ['name', 'email']  // Both required, but email can be null
);
```

Nullable works with `anyOf` to create truly optional polymorphic fields:

```php
use Prism\Prism\Schema\AnyOfSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    'user_input',
    'User input that may be missing',
    [
        new AnyOfSchema(
            schemas: [
                new StringSchema('text', 'Text input'),
                new NumberSchema('number', 'Numeric input'),
            ],
            name: 'user_value',
            description: 'User provided value, or null if not provided',
            nullable: true  // Adds null as a valid type
        ),
    ],
    ['user_value']
);

// Result can be string, number, or null
```

### Combining Tools with Structured Output

Gemini natively supports combining custom tools with structured output. The AI can call tools to gather data, then return a structured response:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;

$schema = new ObjectSchema(
    name: 'weather_analysis',
    description: 'Analysis of weather conditions',
    properties: [
        new StringSchema('summary', 'Summary of the weather'),
        new StringSchema('recommendation', 'Recommendation based on weather'),
    ],
    requiredFields: ['summary', 'recommendation']
);

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('location', 'The city and state')
    ->using(fn (string $location): string => "Weather in {$location}: 72°F, sunny");

$response = Prism::structured()
    ->using('gemini', 'gemini-2.0-flash')
    ->withSchema($schema)
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in San Francisco and should I wear a coat?')
    ->asStructured();

// Access structured output
dump($response->structured);

// Access tool execution details
foreach ($response->toolCalls as $toolCall) {
    echo "Called: {$toolCall->name}\n";
}
```

> [!IMPORTANT]
> When combining tools with structured output, set `maxSteps` to at least 2.

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

## Caching

Prism supports Gemini prompt caching, though due to Gemini requiring you first upload the cached content, it works a little differently to other providers.

To store content in the cache, use the Gemini provider cache method as follows:

```php

use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
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
use Prism\Prism\Facades\Prism;

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
use Prism\Prism\Facades\Prism;

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
use Prism\Prism\Facades\Prism;

Prism::embeddings()
    ->using(Provider::Gemini, 'text-embedding-004')
    ->fromInput('The food was delicious and the waiter...')
    ->withProviderOptions(['outputDimensionality' => 768])
    ->asEmbeddings();
```

### Thinking Mode

Gemini 2.5 series models use an internal "thinking process" during response generation. Thinking is on by default as these models have the ability to automatically decide when and how much to think based on the prompt. If you would like to customize how many tokens the model may use for thinking, or disable thinking altogether, utilize the `withProviderOptions()` method, and pass through an array with a key value pair with `thinkingBudget` and an integer representing the budget of tokens. Set this value to `0` to disable thinking.

```php
use Prism\Prism\Facades\Prism;
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

## Streaming

Gemini supports streaming responses in real-time. All the standard streaming methods work with Gemini models:

```php
return Prism::text()
    ->using('gemini', 'gemini-2.5-flash-preview')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Streaming with Thinking

Models with thinking capabilities stream their reasoning process separately:

```php
use Prism\Prism\Enums\StreamEventType;

foreach ($stream as $event) {
    match ($event->type()) {
        StreamEventType::ThinkingDelta => echo "[Thinking] " . $event->delta,
        StreamEventType::TextDelta => echo $event->delta,
        default => null,
    };
}
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Media Support

Gemini has robust support for processing multimedia content.

### Media Resolution

Gemini 3 models support the `mediaResolution` provider option to control the quality vs token usage tradeoff for images, videos, documents, and audio. Higher resolutions improve fine detail recognition but increase token consumption.

| Resolution | Image Tokens | Video Tokens (per frame) | PDF Tokens |
|------------|--------------|--------------------------|------------|
| `MEDIA_RESOLUTION_LOW` | 280 | 70 | 280 + text |
| `MEDIA_RESOLUTION_MEDIUM` | 560 | 70 | 560 + text |
| `MEDIA_RESOLUTION_HIGH` | 1120 | 280 | 1120 + text |

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-3-flash-preview')
    ->withMessages([
        new UserMessage(
            'Read the fine print in this document.',
            additionalContent: [
                Image::fromLocalPath('/path/to/document.png')
                    ->withProviderOptions(['mediaResolution' => 'MEDIA_RESOLUTION_HIGH']),
            ],
        ),
    ])
    ->asText();
```

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

## Image Generation

Prism supports Gemini image generation through Imagen and Gemini models. See Gemini [image generation docs](https://ai.google.dev/gemini-api/docs/image-generation) for full usage.

### Supported Models

| Model                                       | Description                                        |
| ------------------------------------------- | -------------------------------------------------- |
| `gemini-2.0-flash-preview-image-generation` | Experimental gemini image generation model.        |
| `imagen-4.0-generate-001`                   | Latest Imagen model. Good for HD image generation. |
| `imagen-4.0-ultra-generate-001`             | Highest quality images, only one image per request |
| `imagen-4.0-fast-generate-001`              | Fastest Imagen 4 model                             |
| `imagen-3.0-generate-002`                   | Imagen 3                                           |

### Basic Usage

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
    ->withPrompt('Generate an image of ducklings wearing rubber boots')
    ->generate();

file_put_contents('image.png', base64_decode($response->firstImage()->base64));

// gemini models return usage and metadata
echo $response->usage->promptTokens;
echo $response->meta->id;
```

### Image Editing with Gemini

```php
$originalImage = fopen('image/boots.png', 'r');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.0-flash-preview-image-generation')
    ->withPrompt('Actually, could we make those boots red?')
    ->withProviderOptions([
        'image' => $originalImage,
        'image_mime_type' => 'image/png',
    ])
    ->generate();

file_put_contents('new-boots.png', base64_decode($response->firstImage()->base64));
```

### Image options for Imagen models

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'imagen-4.0-generate-001')
    ->withPrompt('Generate an image of a magnificent building falling into the ocean')
    ->withProviderOptions([
        'n' => 3,                               // number of images to generate
        'size' => '2K',                         // 1K (default), 2K
        'aspect_ratio' => '16:9',               // 1:1 (default), 3:4, 4:3, 9:16, 16:9
        'person_generation' => 'dont_allow',    // dont_allow, allow_adult, allow_all
    ])
    ->generate();
```

Note:

- Imagen 4 Ultra can only generate 1 image at a time.
- An empty response is sent if the prompt is in violation of the person_generation policy, causing Prism to throw an Exception.

### Response Format

All generated images are returned as base64 encoded strings.
