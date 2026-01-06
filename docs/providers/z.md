# Z AI
## Configuration

```php
'z' => [
    'url' => env('Z_URL', 'https://api.z.ai/api/coding/paas/v4'),
    'api_key' => env('Z_API_KEY', ''),
]
```

## Text Generation

Generate text responses with Z AI models:

```php
$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('Write a short story about a robot learning to love')
    ->asText();

echo $response->text;
```

## Multi-modal Support

Z AI provides comprehensive multi-modal capabilities through the `glm-4.6v` model, allowing you to work with images, documents, and videos in your AI requests.

### Images

Z AI supports image analysis through URLs using the `glm-4.6v` model:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using('z', 'glm-4.6v')
    ->withMessages([
        new UserMessage(
            'What is in this image?',
            additionalContent: [
                Image::fromUrl('https://example.com/image.png'),
            ]
        ),
    ])
    ->asText();
```

### Documents

Process documents directly from URLs:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using('z', 'glm-4.6v')
    ->withMessages([
        new UserMessage(
            'What does this document say about?',
            additionalContent: [
                Document::fromUrl('https://example.com/document.pdf'),
            ]
        ),
    ])
    ->asText();
```

### Videos

Z AI can analyze video content from URLs:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\UserMessage;

$response = Prism::text()
    ->using('z', 'glm-4.6v')
    ->withMessages([
        new UserMessage(
            'What does this video show?',
            additionalContent: [
                Video::fromUrl('https://example.com/video.mp4'),
            ]
        ),
    ])
    ->asText();
```

### Combining Multiple Media Types

You can combine images, documents, and videos in a single request:

```php
$response = Prism::text()
    ->using('z', 'glm-4.6v')
    ->withMessages([
        new UserMessage(
            'Analyze this image, document, and video together',
            additionalContent: [
                Image::fromUrl('https://example.com/image.png'),
                Document::fromUrl('https://example.com/document.txt'),
                Video::fromUrl('https://example.com/video.mp4'),
            ]
        ),
    ])
    ->asText();
```

## Tools and Function Calling

Z AI supports function calling, allowing the model to execute your custom tools during conversation.

### Basic Tool Usage

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('city', 'The city and state')
    ->using(fn (string $city): string => "Weather in {$city}: 72°F, sunny");

$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('What is the weather in San Francisco?')
    ->withTools([$weatherTool])
    ->asText();
```

### Multiple Tools

Z AI can use multiple tools in a single request:

```php
$tools = [
    Tool::as('get_weather')
        ->for('Get current weather for a location')
        ->withStringParameter('city', 'The city that you want the weather for')
        ->using(fn (string $city): string => 'The weather will be 45° and cold'),

    Tool::as('search_games')
        ->for('Search for current game times in a city')
        ->withStringParameter('city', 'The city that you want the game times for')
        ->using(fn (string $city): string => 'The tigers game is at 3pm in detroit'),
];

$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withTools($tools)
    ->withMaxSteps(4)
    ->withPrompt('What time is the tigers game today in Detroit and should I wear a coat?')
    ->asText();
```

### Tool Choice

Control when tools are called:

```php
use Prism\Prism\Enums\ToolChoice;

// Require at least one tool to be called
$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('Search for information')
    ->withTools([$searchTool, $weatherTool])
    ->withToolChoice(ToolChoice::Any)
    ->asText();

// Require a specific tool to be called
$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('Get the weather')
    ->withTools([$searchTool, $weatherTool])
    ->withToolChoice(ToolChoice::from('get_weather'))
    ->asText();

// Let the model decide (default)
$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('What do you think?')
    ->withTools([$tools])
    ->withToolChoice(ToolChoice::Auto)
    ->asText();
```

For complete tool documentation, see [Tools & Function Calling](/core-concepts/tools-function-calling).

## Structured Output

Z AI supports structured output through schema-based JSON generation, ensuring responses match your defined structure.

### Basic Structured Output

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\BooleanSchema;

$schema = new ObjectSchema(
    'interview_response',
    'Structured response from AI interviewer',
    [
        new StringSchema('message', 'The interviewer response message'),
        new EnumSchema(
            'action',
            'The next action to take',
            ['ask_question', 'ask_followup', 'complete_interview']
        ),
        new BooleanSchema('is_question', 'Whether this contains a question'),
    ],
    ['message', 'action', 'is_question']
);

$response = Prism::structured()
    ->using('z', 'glm-4.6')
    ->withSchema($schema)
    ->withPrompt('Conduct an interview')
    ->asStructured();

// Access structured data
dump($response->structured);
// [
//     'message' => '...',
//     'action' => 'ask_question',
//     'is_question' => true
// ]
```

For complete structured output documentation, see [Structured Output](/core-concepts/structured-output).

## Limitations

### Media Types

- Does not support `Image::fromPath` or `Image::fromBase64` - only `Image::fromUrl`
- Does not support `Document::fromPath` or `Document::fromBase64` - only `Document::fromUrl`
- Does not support `Video::fromPath` or `Video::fromBase64` - only `Video::fromUrl`

All media must be provided as publicly accessible URLs that Z AI can fetch and process.
