# Groq
## Configuration

```php
'groq' => [
    'api_key' => env('GROQ_API_KEY', ''),
    'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
],
```

## Text, tools and images

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$tool = Tool::as('multiply')
    ->for('Multiply two numbers')
    ->withNumberParameter('left', 'First factor')
    ->withNumberParameter('right', 'Second factor')
    ->using(fn (float $left, float $right): string => (string) ($left * $right));

$response = Prism::text()
    ->using('groq', 'llama-3.3-70b-versatile')
    ->withTools([$tool])
    ->withMaxSteps(3)
    ->withPrompt('What is 12 times 13?')
    ->asText();
```

Groq tool choice accepts `ToolChoice::Auto`, `ToolChoice::Any` or a tool name. `ToolChoice::None` is rejected by this integration. Approval-required and client-executed tools are supported; see [Human-in-the-Loop](/core-concepts/human-in-the-loop).

With a vision-capable model, pass `Image::fromUrl()`, local image bytes or base64 in a user message as described in [Images](/input-modalities/images). URLs are passed to Groq as references. Choose models from [Groq's model catalog](https://console.groq.com/docs/models) for the capability you need.

## Structured output

```php
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

$response = Prism::structured()
    ->using('groq', 'llama-3.3-70b-versatile')
    ->withSchema(new ObjectSchema(
        'answer', 'An answer', [new StringSchema('text', 'Answer text')],
        requiredFields: ['text'],
    ))
    ->withPrompt('Describe a database index.')
    ->asStructured();
```

The structured handler sends `json_object` mode and schema instructions. Validate application constraints after decoding; this path does not send a native JSON Schema constraint.

## Errors

HTTP 429 raises `PrismRateLimitedException` with parsed rate-limit buckets and retry-after information; 413 raises `PrismRequestTooLargeException`, and 529 raises `PrismProviderOverloadedException`. Other failed HTTP requests include Groq's error details. See [Error Handling](/advanced/error-handling).

## Streaming

The Groq integration supports the standard streaming methods:

```php
return Prism::text()
    ->using('groq', 'llama-3.3-70b-versatile')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Token Usage

Groq's `prompt_tokens` includes the tokens it served from the cache, so Prism takes them out to keep the input buckets apart (see [Token Usage](/core-concepts/text-generation#token-usage)). Text, structured and streamed responses all report usage like this:

| Usage field | What it holds |
|-------------|---------------|
| `promptTokens` | `prompt_tokens` minus `prompt_tokens_details.cached_tokens` |
| `cacheReadInputTokens` | `prompt_tokens_details.cached_tokens`, `null` when Groq leaves it out |
| `cacheWriteInputTokens` | Always `null`, Groq doesn't report cache writes |
| `completionTokens` | `completion_tokens`, reasoning included |
| `thoughtTokens` | `completion_tokens_details.reasoning_tokens`, already part of `completionTokens` |

Live responses show reasoning tokens inside `completion_tokens`. The cache split follows Groq's [prompt caching docs](https://console.groq.com/docs/prompt-caching), which describe `cached_tokens` as part of `prompt_tokens`.

Streams can carry usage in more than one place: at the top level, under `x_groq.usage`, and again in a trailing chunk with no choices. They're all the same totals, so Prism reads one of them per response and counts it once. The `StreamEndEvent` adds up every step of a tool loop, and each `StepFinishEvent` carries its own step's usage.

## Audio Processing

The Groq integration supports text-to-speech and speech-to-text requests.

### Text-to-Speech

Use Groq's [Orpheus TTS models](https://console.groq.com/docs/text-to-speech/orpheus) for English or Saudi Arabic speech. Select a voice supported by the chosen model.

#### Basic TTS Usage

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('groq', 'canopylabs/orpheus-v1-english')
    ->withInput('Hello, welcome to our application!')
    ->withVoice('troy')
    ->asAudio();

// Save the audio file
$audioData = base64_decode($response->audio->base64);
file_put_contents('welcome.wav', $audioData);
```

#### TTS Configuration Options

Control audio format and quality:

```php
$response = Prism::audio()
    ->using('groq', 'canopylabs/orpheus-v1-english')
    ->withInput('Testing different audio settings.')
    ->withVoice('hannah')
    ->withProviderOptions([
        'response_format' => 'wav',        // wav (default)
    ])
    ->asAudio();

echo "Audio type: " . ($response->audio->mimeType() ?? 'unknown');
```

#### Arabic Text-to-Speech

```php
$response = Prism::audio()
    ->using('groq', 'canopylabs/orpheus-arabic-saudi')
    ->withInput('مرحبا بكم في تطبيقنا')
    ->withVoice('fahad')
    ->asAudio();

file_put_contents('arabic_speech.wav', base64_decode($response->audio->base64));
```

### Speech-to-Text

Use a supported Whisper model to transcribe audio:

#### Basic STT Usage

```php
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromLocalPath('/path/to/recording.mp3');

$response = Prism::audio()
    ->using('groq', 'whisper-large-v3')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

#### Model Selection Guide

Choose the right model for your use case:

```php
$response = Prism::audio()
    ->using('groq', 'whisper-large-v3')
    ->withInput($audioFile)
    ->asText();

// For fastest English-only transcription
$response = Prism::audio()
    ->using('groq', 'distil-whisper-large-v3-en')
    ->withInput($audioFile)
    ->asText();

// For balanced speed and multilingual capability
$response = Prism::audio()
    ->using('groq', 'whisper-large-v3-turbo')
    ->withInput($audioFile)
    ->asText();
```

#### Language Detection and Specification

Whisper can automatically detect languages or you can specify them:

```php
$response = Prism::audio()
    ->using('groq', 'whisper-large-v3')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'es',           // ISO-639-1 code (optional)
        'temperature' => 0.2,         // Lower for more focused results
    ])
    ->asText();
```

#### Response Formats

Get transcriptions in different formats:

```php
// Standard JSON response
$response = Prism::audio()
    ->using('groq', 'whisper-large-v3')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'json',  // json, text, verbose_json
    ])
    ->asText();

// Verbose JSON includes timestamps and segments
$response = Prism::audio()
    ->using('groq', 'whisper-large-v3')
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
}
```

Prism does not forward `timestamp_granularities` to Groq. Use the segment information returned by `verbose_json` when available.

#### Transcription Usage

Groq bills transcription by the length of the audio, and its transcription responses don't include a `usage` object. So `$response->usage` is `null` rather than zero tokens. If you need the audio length, `verbose_json` responses carry it in `$response->additionalContent['duration']`. Keep in mind that's the audio length, not necessarily what Groq bills (it has a minimum billed length per request).

If a response does include token counts, Prism maps them the same way as text responses (see [Token Usage](#token-usage)). A duration-style `usage` object (`type: duration`) leaves `usage` as `null` and puts the seconds in `$response->additionalContent['usage_seconds']`, matching the OpenAI provider.

#### Context and Prompts

Improve transcription accuracy with context:

```php
$response = Prism::audio()
    ->using('groq', 'whisper-large-v3')
    ->withInput($audioFile)
    ->withProviderOptions([
        'prompt' => 'This is a technical discussion about machine learning and artificial intelligence.',
        'language' => 'en',
        'temperature' => 0.1,         // Lower temperature for technical content
    ])
    ->asText();
```

#### Creating Audio Objects

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From local file path
$audio = Audio::fromLocalPath('/path/to/audio.mp3');

// From remote URL (recommended for large files)
$audio = Audio::fromUrl('https://example.com/recording.wav')->fetchUrlContent(); // a URL you trust

// From base64 encoded data
$audio = Audio::fromBase64($base64AudioData, 'audio/mpeg');

// From binary content
$audioContent = file_get_contents('/path/to/audio.wav');
$audio = Audio::fromRawContent($audioContent, 'audio/wav');
```
