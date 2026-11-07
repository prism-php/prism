# OpenAI
## Configuration

```php
'openai' => [
    'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
    'api_key' => env('OPENAI_API_KEY', ''),
    'organization' => env('OPENAI_ORGANIZATION', null),
]
```

## Provider-specific options
### Strict Tool Schemas

Prism supports OpenAI's [function calling with Structured Outputs](https://platform.openai.com/docs/guides/function-calling#function-calling-with-structured-outputs) via provider-specific meta.

```php
Tool::as('search') // [!code focus]
    ->for('Searching the web')
    ->withStringParameter('query', 'the detailed search query')
    ->using(fn (): string => '[Search results]')
    ->withProviderOptions([ // [!code focus]
      'strict' => true, // [!code focus]
    ]); // [!code focus]
```

### Strict Structured Output Schemas

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'schema' => [ // [!code focus]
            'strict' => true // [!code focus]
        ] // [!code focus]
    ]) // [!code focus]
```

> [!WARNING]
> **All Fields Must Be Required**: When using structured outputs with OpenAI (especially in strict mode), you must include ALL fields in the `requiredFields` array. Fields that should be optional must be marked with `nullable: true` instead. This is an OpenAI API requirement and applies to all structured output requests.
>
> ```php
> new ObjectSchema(
>     name: 'user',
>     properties: [
>         new StringSchema('email', 'Email address'),
>         new StringSchema('bio', 'Optional bio', nullable: true),
>     ],
>     requiredFields: ['email', 'bio'] // ✅ All fields listed
> );
> ```
>
> For more details on required vs nullable fields, see [Schemas - Required vs Nullable Fields](/core-concepts/schemas#required-vs-nullable-fields).

### Structured Output Mode

Prism sends OpenAI's native `json_schema` response format for every model by default, including new families like `gpt-6.x`, `gpt-5.6`, `o3` and `o4`, unknown model names, proxies and fine-tunes of those models. OpenAI treats an omitted `strict` as `strict: true`, so your schema is enforced.

Only a short list of legacy models falls back to JSON mode (a `json_object` response format plus a system message describing your schema): `gpt-4`, `gpt-4-*` snapshots (like `gpt-4-turbo`), `gpt-3.5-turbo*`, `gpt-4o-2024-05-13`, and fine-tunes of those. `o1-mini` and `o1-preview` don't support structured output at all.

Strict schemas have two OpenAI requirements:

- every property must be listed in `requiredFields` (use `nullable: true` for optional values, see the warning above)
- objects must not allow additional properties. Prism's `ObjectSchema` already sends `additionalProperties: false` unless you set `allowAdditionalProperties: true`

If your schema can't meet those rules, either relax strict mode with `'schema' => ['strict' => false]`, or opt out of `json_schema` entirely:

```php
use Prism\Prism\Enums\StructuredMode;

$response = Prism::structured()
    ->using('openai', 'my-custom-model')
    ->withSchema($schema)
    ->usingStructuredMode(StructuredMode::Json) // [!code focus]
    ->withPrompt('...')
    ->asStructured();
```

Asking for `StructuredMode::Structured` explicitly always sends `json_schema`, whatever the model is called.

> [!NOTE]
> Earlier versions sent unknown or custom model names through JSON mode. If you relied on that for a model that can't handle strict schemas, add `->usingStructuredMode(StructuredMode::Json)`.

### Combining Tools with Structured Output

```php
use Prism\Prism\Facades\Prism;
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
    ->using('openai', 'gpt-4o')
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
> When combining tools with structured output, set `maxSteps` to at least 2. OpenAI automatically uses the `/responses` endpoint and sets `parallel_tool_calls: false`.

For complete documentation on combining tools with structured output, see [Structured Output - Combining with Tools](/core-concepts/structured-output#combining-structured-output-with-tools).

### Refusals

When an OpenAI model declines a request it returns a refusal instead of text. Prism reports this as `FinishReason::Refusal` and puts the refusal message in `additionalContent['refusal']`. Nothing is thrown, so check the finish reason before trusting the output:

```php
use Prism\Prism\Enums\FinishReason;

$response = Prism::structured()
    ->using('openai', 'gpt-6.1-sol')
    ->withSchema($schema)
    ->withPrompt($prompt)
    ->asStructured();

if ($response->finishReason === FinishReason::Refusal) {
    logger()->warning('OpenAI refused', ['reason' => $response->additionalContent['refusal']]);
}
```

For structured requests `structured` is an empty array on refusal (the refusal is never parsed as JSON). Text and streaming responses use the same finish reason and key; on a stream they're on the `StreamEndEvent`. Tool calls in a refused streamed turn are not executed.

> [!NOTE]
> Earlier versions threw a `PrismException` for structured refusals and ignored refusals in text and streams.

### Commentary and Final Answers

Newer models such as `gpt-6.x` label every assistant message with a `phase`: `commentary` for progress updates (like "I'll check the weather first") and `final_answer` for the answer itself. Prism handles them like this:

- `text` on a step or response is the `final_answer` text when there is one. Steps that end in tool calls usually only have commentary, so their `text` is that commentary.
- `additionalContent['phase']` holds the phase of the step's last message.
- Structured output is decoded from the final answer, never from commentary.

> [!NOTE]
> Earlier versions only read the last output item, so tool-call steps often had empty text. That text can now contain the model's commentary.

### Conversation Replay and Reasoning Items

Each step keeps the response's message, reasoning and function call items, in their original order, in `additionalContent['output_items']`. When you send those messages back (the next tool-loop request, or a conversation you stored and reloaded), Prism replays these items exactly as OpenAI returned them: commentary before its tool calls, every reasoning item with its `encrypted_content`, and each message's `phase`, which OpenAI asks you to resend.

```php
$response = Prism::text()
    ->using('openai', 'gpt-6.1-sol')
    ->withProviderOptions(['store' => false])
    ->withPrompt('What is 17 × 23?')
    ->asText();

// Store $response->messages however you like, then continue the conversation.
$followUp = Prism::text()
    ->using('openai', 'gpt-6.1-sol')
    ->withProviderOptions(['store' => false])
    ->withMessages([...$response->messages, new UserMessage('Now double it.')])
    ->asText();
```

Because reasoning items carry their `encrypted_content`, reasoning survives across turns with `store: false` too, which is what you want for Zero Data Retention. If you rebuild `AssistantMessage` objects from storage, keep `additional_content` intact. Messages without `output_items` (for example ones saved by older versions of Prism) replay the way they always have. To drop replay state on purpose, remove the `output_items` key.

A step that doesn't finish with tool calls (a refusal, or an incomplete or failed response) never runs its function calls, so Prism stores it without them: its `output_items` leave out the `function_call` items and any reasoning that only led up to them, and the step has no `toolCalls`. Continuing after a refusal sends back the refusal message alone, so don't expect tool calls from that turn. The untouched response is still in the step's `raw`.

When streaming, the same keys are on each step's `StepFinishEvent::$additionalContent`, and Prism uses the completed items from `response.output_item.done`.

### Metadata

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'metadata' => [ // [!code focus]
            'project_id' => 23 // [!code focus]
        ] // [!code focus]
    ]) // [!code focus]
```

### Previous Responses

Prism supports OpenAI's [conversation state](https://platform.openai.com/docs/guides/conversation-state#openai-apis-for-conversation-state) with the `previous_response_id` parameter.

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'previous_response_id' => 'response_id' // [!code focus]
    ]) // [!code focus]
```

### Truncation

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'truncation' => 'auto' // [!code focus]
    ]) // [!code focus]
```

### Service Tiers

Prism supports OpenAI's [Service Tier Configuration](https://platform.openai.com/docs/api-reference/chat/create#chat-create-service_tier) via provider-specific meta.

```php
$response = Prism::text()
    ->withProviderOptions([ // [!code focus]
        'service_tier' => 'priority' // [!code focus]
    ]) // [!code focus]
```

> [!WARNING]
> **Priority Service Tiers increase Cost**: Using priority service tier may reduce response time but increases token costs.
>
> 
### Reasoning Models

OpenAI's reasoning models like `gpt-5`, `gpt-5-mini`, and `gpt-5-nano` use advanced reasoning capabilities to think through complex problems before responding. These models excel at multi-step problem solving, coding, scientific reasoning, and complex analysis tasks.

#### Reasoning Effort

Control how much reasoning the model performs before generating a response using the `reasoning` parameter:

```php
$response = Prism::text()
    ->using('openai', 'gpt-5')
    ->withPrompt('Write a PHP function to implement a binary search algorithm with proper error handling')
    ->withProviderOptions([ // [!code focus]
        'reasoning' => ['effort' => 'high'] // [!code focus]
    ]) // [!code focus]
    ->asText();
```

Available reasoning effort levels:

- **`low`**: Faster responses with economical token usage, suitable for simpler tasks
- **`medium`**: Balanced approach between speed and reasoning depth (default)
- **`high`**: More thorough reasoning for complex problems requiring deep analysis

Newer models add more levels, and each family accepts a different set (checked against the API in October 2026):

| Model | Accepted `effort` values |
| --- | --- |
| `gpt-6.1-sol`, `gpt-6-astra` | `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-6-sol`, `gpt-6-luna`, `gpt-5.6-*` | `none`, `low`, `medium`, `high`, `xhigh`, `max` |
| `gpt-5.5` | `none`, `low`, `medium`, `high`, `xhigh` |

None of these accept `minimal`. Prism passes the value through as given, so an unsupported one comes back as a `PrismException` with OpenAI's error message.

> [!WARNING]
> `gpt-6.x`, `gpt-5.6` and `gpt-5.5` restrict sampling parameters unless reasoning effort is `none`. `temperature` is only accepted at `1`, which changes nothing, and `top_p` is rejected at any value, `1` included. Leave `usingTemperature()` and `usingTopP()` unset for these models, or pair them with `'reasoning' => ['effort' => 'none']` on a model that supports it (not `gpt-6.1-sol` or `gpt-6-astra`), where both are honoured.

> [!NOTE]
> Reasoning models generate internal "reasoning tokens" that help them think through problems. These tokens are included in your usage costs but aren't visible in the response.

#### Reasoning Token Usage

You can track reasoning token usage through the response's usage information:

```php
$response = Prism::text()
    ->using('openai', 'gpt-5-mini')
    ->withPrompt('Refactor this PHP code to use dependency injection')
    ->withProviderOptions([
        'reasoning' => ['effort' => 'medium']
    ])
    ->asText();

// Access reasoning token usage
$usage = $response->firstStep()->usage;
echo "Reasoning tokens: " . $usage->thoughtTokens;
echo "Total completion tokens: " . $usage->completionTokens;
```

#### Text Verbosity

```php
$response = Prism::text()
    ->using('openai', 'gpt-5')
    ->withPrompt('Explain dependency injection')
    ->withProviderOptions([ // [!code focus]
        'text_verbosity' => 'low' // low, medium, high // [!code focus]
    ]) // [!code focus]
    ->asText();
```

#### Store

```php
$response = Prism::text()
    ->using('openai', 'gpt-5')
    ->withPrompt('Give me a summary of the following legal document')
    ->withProviderOptions([ // [!code focus]
        'store' => false // true, false // [!code focus]
    ]) // [!code focus]
    ->asText();
```

`store` is sent on every request Prism makes, including streams and the follow-up requests of a tool loop. With `store: false` Prism still replays reasoning across turns using each reasoning item's `encrypted_content` (see [Conversation Replay and Reasoning Items](#conversation-replay-and-reasoning-items)).

> [!NOTE]
> Earlier versions didn't send `store` on streaming requests.

## Streaming

OpenAI supports streaming responses in real-time. All the standard streaming methods work with OpenAI models:

```php
// Stream events
$stream = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt('Write a story')
    ->asStream();

// Server-Sent Events
return Prism::text()
    ->using('openai', 'gpt-4o')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

### Stream Endings

A stream ends with exactly one `StreamEndEvent`, whose `finishReason` reflects how OpenAI finished the response:

- `response.completed` maps to `Stop`, `ToolCalls` or `Refusal`
- `response.incomplete` maps to `Length` (or `ContentFilter`), with the usage OpenAI reported
- `response.failed` emits an `ErrorEvent` first, then ends with `Error`

`additionalContent` on the `StreamEndEvent` includes the response `status` and any `incomplete_details`. Prism only runs your tools when the turn finished with `ToolCalls`. Tool calls from a refused, truncated or failed turn are never executed. Their `ToolCallEvent`s are still emitted, but the `StepFinishEvent` reports the step's `finishReason` and its `output_items` leave them out, so the `AssistantMessage` `StreamedMessages::fromEvents()` builds for that step has no tool calls to replay.

### Streaming Reasoning Models

Reasoning models like `gpt-5` stream their thinking process separately from the final answer:

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

### Streaming with Provider Tools

OpenAI's provider tools like `image_generation` emit streaming events during execution, letting you track progress and access results in real-time:

```php
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\Streaming\Events\ProviderToolEvent;

$stream = Prism::text()
    ->using('openai', 'gpt-4o')
    ->withProviderTools([
        new ProviderTool('image_generation'),
    ])
    ->withPrompt('Generate an image of a sunset over mountains')
    ->asStream();

foreach ($stream as $event) {
    if ($event instanceof ProviderToolEvent) {
        // Check when image generation completes
        if ($event->status === 'completed' && isset($event->data['result'])) {
            $imageData = $event->data['result']; // base64 PNG
            file_put_contents('generated.png', base64_decode($imageData));
        }
    }
}
```

For complete details on handling provider tool events, see [Streaming Output](/core-concepts/streaming-output).

### Caching

Automatic caching does not currently work with JsonMode. Please ensure you use StructuredMode if you wish to utilise automatic caching.

OpenAI's `input_tokens` includes the tokens it read from the cache and the tokens it wrote to it. Prism splits that total into buckets that don't overlap, the same way it does for Anthropic, so text, structured and streamed responses all report usage like this:

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `input_tokens` minus cache reads and cache writes, so only the uncached input |
| `cacheReadInputTokens` | `input_tokens_details.cached_tokens` |
| `cacheWriteInputTokens` | `input_tokens_details.cache_write_tokens` |
| `completionTokens` | `output_tokens`, reasoning tokens included |
| `thoughtTokens` | `output_tokens_details.reasoning_tokens` |

Add `promptTokens`, `cacheReadInputTokens` and `cacheWriteInputTokens` together to get OpenAI's `input_tokens` back. `thoughtTokens` is already part of `completionTokens`, so don't add those two.

## Provider Tools

OpenAI offers built-in provider tools that can be used alongside your custom tools. These tools are executed by OpenAI's infrastructure and provide specialized capabilities. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

### Code Interpreter

The OpenAI code interpreter allows your AI to execute Python code in a secure, sandboxed environment. This is particularly useful for mathematical calculations, data analysis, and code execution tasks.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\ProviderTool;

Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withPrompt('Solve the equation 3x + 10 = 14.')
    ->withProviderTools([
        new ProviderTool(type: 'code_interpreter', options: ['container' => ['type' => 'auto']])
    ])
    ->asText();
```

#### Configuration Options

- **container**: Configure the execution environment
  - `type`: Set to `'auto'` for automatic environment selection

## Additional Message Attributes

Adding optional parameters to a `UserMessage` like the `name` field can be done through the `additionalAttributes` parameter.

```php
Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withMessages([
        new UserMessage('Who are you?', additionalAttributes: ['name' => 'TJ']),
    ])
    ->asText()
```

## Image Generation

OpenAI provides powerful image generation capabilities through multiple models. Prism supports all of OpenAI's image generation models with their full feature sets.

### Supported Models

| Model | Description |
|-------|-------------|
| `dall-e-3` | Latest DALL-E model |
| `dall-e-2` | Previous generation |
| `gpt-image-1` | GPT-based image model |

### Basic Usage

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

$image = $response->firstImage();
echo $image->url; // Generated image URL
```

### DALL-E 3 Options

DALL-E 3 is the most advanced model with the highest quality output:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('A futuristic cityscape with flying cars')
    ->withProviderOptions([
        'size' => '1792x1024',          // 1024x1024, 1024x1792, 1792x1024
        'quality' => 'hd',              // standard, hd
        'style' => 'vivid',             // vivid, natural
    ])
    ->generate();

// DALL-E 3 automatically revises prompts for better results
if ($response->firstImage()->hasRevisedPrompt()) {
    echo "Revised prompt: " . $response->firstImage()->revisedPrompt;
}
```

### DALL-E 2 Options

DALL-E 2 supports generating multiple images and is more cost-effective:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-2')
    ->withPrompt('Abstract geometric patterns')
    ->withProviderOptions([
        'n' => 4,                       // Number of images (1-10)
        'size' => '1024x1024',          // 256x256, 512x512, 1024x1024
        'response_format' => 'url',     // url only
        'user' => 'user-123',           // Optional user identifier
    ])
    ->generate();

// Process multiple images
foreach ($response->images as $image) {
    echo "Image: {$image->url}\n";
}
```

### GPT-Image-1 Options

GPT-Image-1 offers advanced features including image editing and format control:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('A detailed architectural rendering of a modern house')
    ->withProviderOptions([
        'size' => '1536x1024',              // Various sizes supported
        'quality' => 'high',                // standard, high
        'output_format' => 'webp',          // png, webp, jpeg
        'output_compression' => 85,         // Compression level (0-100)
        'background' => 'transparent',      // transparent, white, black
        'moderation' => true,               // Enable content moderation
    ])
    ->generate();
```

### Image Editing with GPT-Image-1

GPT-Image-1 supports sophisticated image editing operations using the `withPrompt` method with Image value objects:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Add a vaporwave sunset to the background', [
        Image::fromLocalPath('tests/Fixtures/diamond.png'),
    ])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->withClientOptions(['timeout' => 9999])
    ->generate();

file_put_contents('edited-image.png', base64_decode($response->firstImage()->base64));
```

#### Using Masks for Targeted Editing

For precise control over which parts of the image to edit, use a mask image:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Add a vaporwave sunset to the background', [
        Image::fromLocalPath('tests/Fixtures/diamond.png'),
    ])
    ->withProviderOptions([
        'mask' => Image::fromLocalPath('tests/Fixtures/diamond-mask.png'),
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();
```

#### Editing with Multiple Images

You can also edit with multiple images for more complex operations. Use the `as()` method to provide custom filenames for better readability:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Combine these images with a futuristic theme', [
        Image::fromLocalPath('tests/Fixtures/diamond.png')->as('diamond.png'),
        Image::fromLocalPath('tests/Fixtures/sunset.png')->as('sunset-background.png'),
    ])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();
```

### Response Format

Generated images are returned as URLs:

```php
$response = Prism::image()
    ->using('openai', 'dall-e-3')
    ->withPrompt('Digital artwork')
    ->generate();

$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "<img src='{$image->url}' alt='Generated image'>";
}
```

## Audio Processing

OpenAI provides comprehensive audio processing capabilities through their TTS (Text-to-Speech) and Whisper (Speech-to-Text) models. Prism supports all of OpenAI's audio models with their full feature sets.


### Text-to-Speech

Convert text into natural-sounding speech with various voice options:

#### Basic TTS Usage

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Hello, welcome to our application!')
    ->withVoice('alloy')
    ->asAudio();

// Save the audio file
$audioData = base64_decode($response->audio->base64);
file_put_contents('welcome.mp3', $audioData);
```

#### High-Definition Audio

For higher quality audio output, use the model:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('This is high-quality audio generation.')
    ->withProviderOptions([
        'voice' => 'nova',
        'response_format' => 'wav',    // Higher quality format
    ])
    ->asAudio();
```

#### Audio Format Options

Control the output format and quality:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Testing different audio formats.')
    ->withProviderOptions([
        'voice' => 'echo',
        'response_format' => 'opus',   // mp3, opus, aac, flac, wav, pcm
        'speed' => 1.25,              // Speed: 0.25 to 4.0
    ])
    ->asAudio();

echo "Audio type: " . $response->audio->getMimeType();
```

For more information on the available options, please refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/text-to-speech).

### Speech-to-Text

Convert audio files into accurate text transcriptions using Whisper:

#### Basic STT Usage

```php
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromPath('/path/to/recording.mp3');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

#### Transcription Usage

OpenAI bills transcription models one of two ways, and `$response->usage` follows suit:

- Token-billed models (like `gpt-4o-transcribe`) report `input_tokens` and `output_tokens`, which land in `promptTokens` and `completionTokens`.
- Duration-billed models (like `whisper-1`) only report the length of the audio. There are no token counts, so `$response->usage` is `null` and the billed seconds are in `$response->additionalContent['usage_seconds']`.

```php
if ($response->usage !== null) {
    echo "Input tokens: {$response->usage->promptTokens}\n";
    echo "Output tokens: {$response->usage->completionTokens}\n";
} else {
    echo 'Billed seconds: '.($response->additionalContent['usage_seconds'] ?? 'not reported')."\n";
}
```

#### Language Detection

Whisper can automatically detect the language or you can specify it:

```php
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'es',           // ISO-639-1 code (optional)
        'temperature' => 0.2,         // Lower temperature for more focused results
    ])
    ->asText();
```

#### Response Formats

Get transcriptions in different formats with varying detail levels:

```php
// Standard JSON response
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'json',  // json, text, srt, verbose_json, vtt
    ])
    ->asText();

// Verbose JSON includes timestamps and confidence scores
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'verbose_json',
    ])
    ->asText();

// Access detailed segment information
$segments = $response->additionalContent['segments'] ?? [];
foreach ($segments as $segment) {
    echo "Text: " . $segment['text'] . "\n";
    echo "Start: " . $segment['start'] . "s\n";
    echo "End: " . $segment['end'] . "s\n";
    echo "Confidence: " . ($segment['no_speech_prob'] ?? 'N/A') . "\n\n";
}
```

#### Subtitle Generation

Generate subtitle files directly:

```php
// SRT format subtitles
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'srt',
    ])
    ->asText();

file_put_contents('subtitles.srt', $response->text);

// VTT format subtitles
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'vtt',
    ])
    ->asText();

file_put_contents('subtitles.vtt', $response->text);
```

#### Context and Prompts

Improve transcription accuracy with context:

```php
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'prompt' => 'This is a technical discussion about machine learning and artificial intelligence.',
        'language' => 'en',
        'temperature' => 0.1,         // Lower temperature for technical content
    ])
    ->asText();
```

### Audio File Handling

#### Creating Audio Objects

Load audio from various sources:

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From local file path
$audio = Audio::fromPath('/path/to/audio.mp3');

// From remote URL
$audio = Audio::fromUrl('https://example.com/recording.wav');

// From base64 encoded data
$audio = Audio::fromBase64($base64AudioData, 'audio/mpeg');

// From binary content
$audioContent = file_get_contents('/path/to/audio.wav');
$audio = Audio::fromContent($audioContent, 'audio/wav');
```

#### File Size Considerations

Whisper has a file size limit of 25 MB. For larger files, consider:

```php
// Check file size before processing
$audio = Audio::fromPath('/path/to/large-audio.mp3');

if ($audio->size() > 25 * 1024 * 1024) { // 25 MB
    echo "File too large for processing";
} else {
    $response = Prism::audio()
        ->using('openai', 'whisper-1')
        ->withInput($audio)
        ->asText();
}
```

For more information on the available options, please refer to the [OpenAI API documentation](https://platform.openai.com/docs/guides/speech-to-text).

## Moderation

OpenAI provides powerful content moderation capabilities through their moderation API. Prism supports both text and image moderation with OpenAI.

### Supported Models

| Model | Description |
|-------|-------------|
| `omni-moderation-latest` | Latest moderation model supporting both text and images |

### Text Moderation

Check text content for potentially harmful or inappropriate material:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Your text to check goes here')
    ->asModeration();

if ($response->isFlagged()) {
    $flagged = $response->firstFlagged();
    // Handle flagged content
}
```

### Image Moderation

Moderate images using the `omni-moderation-latest` model:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(Image::fromUrl('https://example.com/image.png'))
    ->asModeration();

if ($response->isFlagged()) {
    // Handle flagged image
}
```

### Mixed Text and Image Moderation

You can check both text and images in a single request:

```php
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput(
        'Check this text',
        Image::fromStoragePath('uploads/user-photo.jpg', 'public'),
        'Another text to check',
        Image::fromUrl('https://example.com/image.png')
    )
    ->asModeration();
```

> [!NOTE]
> When mixing text and images in a single request, text inputs are treated as context/descriptions for the images, not as separate moderation inputs. If you need separate moderation results for text and images, make separate API calls for each type.

### Multiple Inputs

Check multiple inputs at once:

```php
// Multiple text inputs
$response = Prism::moderation()
    ->using(Provider::OpenAI)
    ->withInput('Text 1', 'Text 2', 'Text 3')
    ->asModeration();

// Multiple images
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput([
        Image::fromUrl('https://example.com/image1.png'),
        Image::fromStoragePath('uploads/image2.jpg', 'public'),
    ])
    ->asModeration();
```

### Response Handling

Access moderation results and category information:

```php
$response = Prism::moderation()
    ->using(Provider::OpenAI, 'omni-moderation-latest')
    ->withInput('Your content here')
    ->asModeration();

// Check if any content was flagged
if ($response->isFlagged()) {
    // Get all flagged results
    $flaggedResults = $response->flagged();
    
    foreach ($flaggedResults as $result) {
        // Access categories
        $categories = $result->categories; // Array of category => bool
        $scores = $result->categoryScores; // Array of category => float
        
        // Check specific categories
        if ($result->categories['hate'] ?? false) {
            // Handle hate content
        }
    }
}
```

For complete moderation documentation, including all available options and use cases, see [Moderation](/core-concepts/moderation).
