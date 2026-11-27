# Audio Processing

Prism provides text-to-speech (TTS) and speech-to-text (STT) requests through a shared API. Supported operations and formats depend on the provider and model.

## Getting Started

### Text-to-Speech

::: warning OpenAI Speech API retirement
OpenAI schedules `tts-1`, `tts-1-hd` and `gpt-4o-mini-tts` for removal on January 6, 2027. Prism uses the Speech API for these calls. The replacement Realtime workflow requires a separate integration; putting its model ID in this builder will not migrate the request. See [OpenAI's TTS guide](https://developers.openai.com/api/docs/guides/text-to-speech).
:::

Convert text into natural-sounding speech:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::audio()
    ->using(Provider::OpenAI, 'gpt-4o-mini-tts')
    ->withInput('Hello, this is a test of text-to-speech functionality.')
    ->withVoice('alloy')
    ->asAudio();

$audio = $response->audio;
if ($audio->hasBase64()) {
    file_put_contents('output.mp3', base64_decode($audio->base64));
    echo "Audio saved as: output.mp3";
}
```

### Speech-to-Text

Convert audio files into text transcriptions:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromLocalPath('/path/to/audio.mp3');

$response = Prism::audio()
    ->using(Provider::OpenAI, 'whisper-1')
    ->withInput($audioFile)
    ->asText();

echo "Transcription: " . $response->text;
```

## Provider Support

Currently, Prism supports audio processing through:

| Provider | Text-to-speech | Speech-to-text |
|---|---|---|
| [OpenAI](/providers/openai#audio-processing) | Yes | Yes |
| [Groq](/providers/groq#audio-processing) | Yes | Yes |
| [ElevenLabs](/providers/elevenlabs) | Yes | Yes |
| [Gemini](/providers/gemini#text-to-speech) | Yes | Not through `asText()`; use audio in a text prompt for understanding |
| [Mistral](/providers/mistral#audio-processing) | No | Yes |
| [Replicate](/providers/replicate#supported-features) | Yes | Yes |

Select models and voices from the provider guide. Initialize TTS requests with `withVoice()` even when a provider reads its model voice from provider options. ElevenLabs TTS also requires `model_id` in provider options; Replicate's voice input must be set there too.

## Basic Usage

### Simple Text-to-Speech

Generate speech from text input:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Welcome to our application!')
    ->withVoice('alloy')
    ->asAudio();

$audio = $response->audio;
echo "Audio type: " . $audio->mimeType(); // audio/mpeg
echo "Has audio data: " . ($audio->hasBase64() ? 'Yes' : 'No');
```

### Simple Speech-to-Text

Transcribe audio files to text:

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From file path
$audioFile = Audio::fromLocalPath('/path/to/recording.wav');

// From a URL you trust — fetched explicitly, see below
$audioFile = Audio::fromUrl('https://example.com/audio.mp3')->fetchUrlContent();

// From base64 data
$audioFile = Audio::fromBase64($base64AudioData, 'audio/wav');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

// Access the transcription
echo $response->text;
```

::: warning URLs are not fetched automatically
Media built with `fromUrl()` has no bytes until you fetch them. Two calls do it:

- `fetchUrlContent()` downloads the file from your server with no checks. Use it only on a URL you control.
- `fetchPublicUrlContent()` is for a URL you did not choose, from user input or model output. It refuses non-http schemes, private, loopback and reserved addresses (including a host name that resolves to one, and any redirect that leads to one), and bodies over 32 MiB, throwing `PrismUrlRefused` with a `code()` such as `private_address_refused`.

For a file a user uploads, use `fromBase64()` or `fromLocalPath()` on the stored file instead.
:::

## Working with Audio Files

### Creating Audio Objects

The `Audio` class provides several ways to work with audio files:

```php
use Prism\Prism\ValueObjects\Media\Audio;

// From local file
$audio = Audio::fromLocalPath('/path/to/audio.mp3');

// From remote URL
$audio = Audio::fromUrl('https://example.com/speech.wav')->fetchUrlContent(); // a URL you trust

// From base64 encoded data
$audio = Audio::fromBase64($base64Data, 'audio/mpeg');

// From raw binary content
$audio = Audio::fromRawContent($binaryData, 'audio/wav');
```

### Audio Properties

Access audio file information:

```php
$audio = Audio::fromLocalPath('/path/to/audio.mp3');

echo "MIME type: " . $audio->mimeType();
echo "Local path: " . ($audio->localPath() ?? 'No local path');
echo "Content size: " . strlen($audio->rawContent() ?? '') . " bytes";
```

## Working with Responses

`asAudio()` returns `AudioResponse` with a `GeneratedAudio` object and `additionalContent`. Use `rawContent()`, `base64()`, `mimeType()` and `hasBase64()` on the generated audio. It has no `getMimeType()` or `size()` method; inspect byte length with `strlen($audio->rawContent() ?? '')`. MIME detection may be unavailable for headerless audio, so preserve the selected output format separately when needed.

### Text-to-Speech Responses

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('This is high-quality text-to-speech.')
    ->withVoice('nova')
    ->asAudio();

// Access the generated audio
$audio = $response->audio;

if ($audio->hasBase64()) {
    // Save to file
    $audioData = base64_decode($audio->base64);
    file_put_contents('speech.mp3', $audioData);
    
    // Get MIME type
    echo "Content type: " . $audio->mimeType();
}

// Access additional response data
foreach ($response->additionalContent as $key => $value) {
    echo "{$key}: {$value}\n";
}
```

### Speech-to-Text Responses

```php
$audioFile = Audio::fromLocalPath('/path/to/speech.mp3');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

// Access the transcription
$text = $response->text;
echo "Transcription: " . $text;

// Token-billed models report token usage
if ($response->usage) {
    echo "Prompt tokens: " . $response->usage->promptTokens;
    echo "Completion tokens: " . $response->usage->completionTokens;
}

// Duration-billed models (like whisper-1) report seconds instead
if (isset($response->additionalContent['usage_seconds'])) {
    echo "Audio seconds: " . $response->additionalContent['usage_seconds'];
}

// Access raw response data
print_r($response->additionalContent);
```

`usage` is `null` when the provider bills by audio duration rather than tokens. Prism doesn't report a duration as zero tokens.

## Voice Selection

Select a text-to-speech voice with `withVoice()`:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Hello, how are you today?')
    ->withVoice('alloy')  // Voice options vary by provider
    ->asAudio();
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Text-to-Speech Options

Customize format, speed, and other options:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Hello, how are you today?')
    ->withVoice('nova')
    ->withProviderOptions([
        'response_format' => 'mp3',           // mp3, opus, aac, flac, wav, pcm
        'speed' => 1.0,                       // 0.25 to 4.0
    ])
    ->asAudio();
```

### OpenAI Speech-to-Text Options

Configure transcription settings:

```php
$audioFile = Audio::fromLocalPath('/path/to/multilingual.mp3');

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'en',
        'prompt' => 'Previous context...'
    ])
    ->asText();
```

### Response Formats

Different response formats provide varying levels of detail:

```php
// Verbose JSON format includes timestamps and confidence scores
$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'response_format' => 'verbose_json',
    ])
    ->asText();

// Access additional metadata
$metadata = $response->additionalContent;
if (isset($metadata['segments'])) {
    foreach ($metadata['segments'] as $segment) {
        echo "Segment: " . $segment['text'] . "\n";
        echo "Start: " . $segment['start'] . "s\n";
        echo "End: " . $segment['end'] . "s\n";
    }
}
```

## Asynchronous transcription methods

The builder also declares `asTextProviderId()` and `asTextAsync()`. The first accepts an `Audio` input and returns `ProviderIdResponse` (`id`, optional `usage`, `additionalContent`); the second accepts a string/integer provider ID and returns `TextResponse`. No built-in provider currently implements these operations: both raise an unsupported-action `PrismException`. Use `asText()` for the supported transcription path, or a provider-specific client for asynchronous jobs.

## Advanced Usage

Audio can be integrated into multi-modal conversations:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Text;

$audioFile = Audio::fromLocalPath('/path/to/question.mp3');

// First transcribe the audio
$transcription = Prism::audio()
    ->using('openai', 'whisper-1')
    ->withInput($audioFile)
    ->asText();

// Then use in a text conversation
$response = Prism::text()
    ->using('openai', 'gpt-4.1')
    ->withMessages([
        new UserMessage('', [
            new Text('User asked: '),
            new Text($transcription->text),
            new Text(' - Please provide a detailed response.')
        ])
    ])
    ->asText();

// Convert response back to speech
$speechResponse = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput($response->text)
    ->withVoice('alloy')
    ->asAudio();
```

## Configuration Options

### Client Configuration

Configure HTTP client options for audio processing:

```php
$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('This might take a while to process.')
    ->withClientOptions([
        'timeout' => 60,           // Increase timeout for large files
        'connect_timeout' => 10,   // Connection timeout
    ])
    ->withClientRetry(3, 1000)     // Retry 3 times with 1s delay
    ->asAudio();
```

### Provider Configuration

Override provider configuration for multi-tenant applications:

```php
$customConfig = [
    'api_key' => 'user-specific-api-key',
    'organization' => 'user-org-id',
];

$response = Prism::audio()
    ->using('openai', 'whisper-1')
    ->usingProviderConfig($customConfig)
    ->withInput($audioFile)
    ->asText();
```

## Error Handling

Handle potential errors in audio processing:

```php
use Prism\Prism\Exceptions\PrismException;
use Throwable;

try {
    $response = Prism::audio()
        ->using('openai', 'gpt-4o-mini-tts')
        ->withInput('Text to convert to speech')
        ->withVoice('alloy')
        ->asAudio();
        
    // Process successful response
    file_put_contents('output.mp3', base64_decode($response->audio->base64));
    
} catch (PrismException $e) {
    Log::error('Audio processing failed:', ['error' => $e->getMessage()]);
    // Handle Prism-specific errors
} catch (Throwable $e) {
    Log::error('General error:', ['error' => $e->getMessage()]);
    // Handle any other errors
}
```

## Testing

Use fakes to test audio functionality without external API calls:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\ValueObjects\GeneratedAudio;

test('can generate text-to-speech', function () {
    $fakeAudio = new AudioResponse(
        audio: new GeneratedAudio(
            base64: base64_encode('fake-audio-data'),
            type: 'audio/mpeg'
        )
    );
    
    Prism::fake([$fakeAudio]);

    $response = Prism::audio()
        ->using('openai', 'gpt-4o-mini-tts')
        ->withInput('Test audio generation')
        ->withVoice('alloy')
        ->asAudio();

    expect($response->audio->hasBase64())->toBeTrue();
    expect($response->audio->mimeType())->toBe('audio/mpeg');
});

test('can transcribe speech-to-text', function () {
    $fakeTranscription = new TextResponse(
        text: 'This is a fake transcription'
    );
    
    Prism::fake([$fakeTranscription]);

    $audioFile = Audio::fromRawContent('owned test audio', 'audio/mpeg');
    
    $response = Prism::audio()
        ->using('openai', 'whisper-1')
        ->withInput($audioFile)
        ->asText();

    expect($response->text)->toBe('This is a fake transcription');
});
```
