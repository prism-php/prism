# Z AI
## Configuration

```php
'z' => [
    'url' => env('Z_URL', 'https://api.z.ai/api/paas/v4'),
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
use Prism\Prism\Facades\Tool;

$weatherTool = Tool::as('get_weather')
    ->for('Get current weather for a location')
    ->withStringParameter('city', 'The city and state')
    ->using(fn (string $city): string => "Weather in {$city}: 72°F, sunny");

$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('What is the weather in San Francisco?')
    ->withTools([$weatherTool])
    ->withMaxSteps(2)
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
    ->withTools($tools)
    ->withToolChoice(ToolChoice::Any)
    ->asText();

// Require a specific tool to be called
$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('Get the weather')
    ->withTools($tools)
    ->withToolChoice('get_weather')
    ->asText();

// Let the model decide (default)
$response = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('What do you think?')
    ->withTools($tools)
    ->withToolChoice(ToolChoice::Auto)
    ->asText();
```

For complete tool documentation, see [Tools & Function Calling](/core-concepts/tools-function-calling).

## Structured Output

Prism sends schema instructions and requests JSON-object output from Z AI. Validate application requirements after decoding.

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

## Streaming

```php
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Facades\Prism;

$stream = Prism::text()
    ->using('z', 'glm-4.6')
    ->withPrompt('Explain the purpose of a database index.')
    ->asStream();

foreach ($stream as $event) {
    if ($event->type() === StreamEventType::TextDelta) {
        echo $event->delta;
    }
}
```

Tools and pending approvals use the shared [streaming events](/core-concepts/streaming-output).

## Structured and provider options

Structured requests use `json_object` mode with schema instructions and force `thinking: {type: disabled}`. This is not a native JSON Schema constraint; validate application requirements after decoding. The text, structured and stream handlers do not merge arbitrary request provider options into their payloads. Setting a value through `withProviderOptions()` does not make it an API option for these operations.

## Token Usage

Z's `prompt_tokens` includes the tokens it served from the cache, so Prism takes them out to keep the input buckets apart (see [Token Usage](/core-concepts/text-generation#token-usage)):

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `prompt_tokens` minus `prompt_tokens_details.cached_tokens` |
| `cacheReadInputTokens` | `prompt_tokens_details.cached_tokens`, or `null` when Z leaves it out |
| `cacheWriteInputTokens` | Always `null`, Z doesn't report cache writes |
| `completionTokens` | `completion_tokens`, including any thinking |
| `thoughtTokens` | Always `null`, Z doesn't report thinking tokens separately |

When streaming, Z sends usage on the last chunk alongside the finish reason and the final delta. Prism reads it from that chunk even when it also carries text or tool calls, and adds up every step of a tool loop.

## Limitations

### Media Types

- Does not support `Image::fromPath` or `Image::fromBase64` - only `Image::fromUrl`
- Does not support `Document::fromPath` or `Document::fromBase64` - only `Document::fromUrl`
- Does not support `Video::fromPath` or `Video::fromBase64` - only `Video::fromUrl`

All media must be provided as publicly accessible URLs that Z AI can fetch and process.
