# ElevenLabs

## Configuration

```php
'elevenlabs' => [
    'api_key' => env('ELEVENLABS_API_KEY', ''),
    'url' => env('ELEVENLABS_URL', 'https://api.elevenlabs.io/v1/'),
]
```

## Speech-to-Text

ElevenLabs provides speech-to-text through their Scribe model with support for diarization and audio event tagging.

### Basic Usage

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromPath('/path/to/recording.mp3');

$response = Prism::audio()
    ->using('elevenlabs', 'scribe_v1')
    ->withInput($audioFile)
    ->asText();
```

## Provider-specific Options

### Language Detection

```php
$response = Prism::audio()
    ->using('elevenlabs', 'scribe_v1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language_code' => 'en',
    ])
    ->asText();
```

### Speaker Diarization

```php
$response = Prism::audio()
    ->using('elevenlabs', 'scribe_v1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'diarize' => true,
        'num_speakers' => 2,
    ])
    ->asText();
```

### Audio Event Tagging

```php
$response = Prism::audio()
    ->using('elevenlabs', 'scribe_v1')
    ->withInput($audioFile)
    ->withProviderOptions([
        'tag_audio_events' => true,
    ])
    ->asText();
```

## Limitations

- Text-to-speech is not yet implemented