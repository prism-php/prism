# Groq
## Configuration

```php
'groq' => [
    'api_key' => env('GROQ_API_KEY', ''),
    'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
],
```

## Streaming

Groq's ultra-fast LPU architecture provides exceptional streaming performance. All standard streaming methods are supported:

```php
return Prism::text()
    ->using('groq', 'llama-3.3-70b-versatile')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

For complete streaming documentation, see [Streaming Output](/core-concepts/streaming-output).

## Audio Processing

Groq provides high-performance audio processing capabilities through their ultra-fast Language Processing Unit (LPU) architecture, enabling both text-to-speech (TTS) and speech-to-text (STT) functionality with exceptional speed and quality.

### Text-to-Speech

Groq offers PlayAI TTS models that can convert text into natural-sounding speech with support for multiple languages and voices.

#### Basic TTS Usage

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('groq', 'playai-tts')
    ->withInput('Hello, welcome to our application!')
    ->withVoice('Fritz-PlayAI')
    ->asAudio();

// Save the audio file
$audioData = base64_decode($response->audio->base64);
file_put_contents('welcome.wav', $audioData);
```

#### TTS Configuration Options

Control audio format and quality:

```php
$response = Prism::audio()
    ->using('groq', 'playai-tts')
    ->withInput('Testing different audio settings.')
    ->withVoice('Celeste-PlayAI')
    ->withProviderOptions([
        'response_format' => 'wav',        // wav (default)
        'speed' => 1.2,                    // Speed: 0.5 to 5.0
        'sample_rate' => 48000,            // Sample rate options: 8000, 16000, 22050, 24000, 32000, 44100, 48000
    ])
    ->asAudio();

echo "Audio type: " . $response->audio->getMimeType();
```

#### Arabic Text-to-Speech

```php
$response = Prism::audio()
    ->using('groq', 'playai-tts-arabic')
    ->withInput('مرحبا بكم في تطبيقنا')
    ->withVoice('Amira-PlayAI')
    ->asAudio();

file_put_contents('arabic_speech.wav', base64_decode($response->audio->base64));
```

### Speech-to-Text

Groq provides ultra-fast speech recognition using Whisper models, offering exceptional speed with real-time factors of up to 299x.

#### Basic STT Usage

```php
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromPath('/path/to/recording.mp3');

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
        'timestamp_granularities' => ['segment'], // word, segment
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
$audio = Audio::fromPath('/path/to/audio.mp3');

// From remote URL (recommended for large files)
$audio = Audio::fromUrl('https://example.com/recording.wav');

// From base64 encoded data
$audio = Audio::fromBase64($base64AudioData, 'audio/mpeg');

// From binary content
$audioContent = file_get_contents('/path/to/audio.wav');
$audio = Audio::fromContent($audioContent, 'audio/wav');
```
