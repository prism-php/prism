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

$audioFile = Audio::fromLocalPath('/path/to/recording.mp3');

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

## Text-to-Speech

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('elevenlabs', 'eleven_multilingual_v2')
    ->withVoice('your-voice-id')
    ->withInput('Welcome to the application.')
    ->withProviderOptions([
        'model_id' => 'eleven_multilingual_v2',
        'speed' => 1.0,
        'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
    ])
    ->asAudio();

file_put_contents('speech.mp3', $response->audio->rawContent());
```

`withVoice()` selects the voice ID in the endpoint. Set the TTS model through the `model_id` provider option: the model passed to `using()` is not mapped to this field. Speech-to-text uses the model from `using()` normally. Choose a model and voice available in your [ElevenLabs account](https://elevenlabs.io/docs/overview/models).

TTS options include:

| Option | Use |
|---|---|
| `model_id` | Synthesis model |
| `voice_settings` | `stability`, `use_speaker_boost`, `similarity_boost`, `style` |
| `speed` | Mapped into `voice_settings.speed` |
| `language_code`, `seed` | Language and generation seed |
| `previous_text`, `next_text` | Adjacent text context |
| `previous_request_ids`, `next_request_ids` | Adjacent generation context |
| `apply_text_normalization`, `apply_language_text_normalization` | Text normalization controls |

Other provider options are forwarded as payload fields. A failed TTS HTTP response throws a generic `\Exception`; catching only `PrismException` does not catch it. Speech-to-text HTTP failures use Laravel's `RequestException`.

## Limitations

This provider implements audio generation and transcription. Text generation, custom tools and embeddings are unsupported. See [Audio](/core-concepts/audio) for shared response handling.
