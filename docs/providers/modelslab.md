# ModelsLab

ModelsLab provides access to image generation, text-to-speech, speech-to-text, and LLM capabilities through a unified API. For the full list of available models and parameters, see the [ModelsLab API Documentation](https://docs.modelslab.com).

## Configuration

```php
'modelslab' => [
    'api_key' => env('MODELSLAB_API_KEY', ''),
    'url' => env('MODELSLAB_URL', 'https://modelslab.com/api/v6/'),
],
```

## Image Generation

ModelsLab supports text-to-image and image-to-image generation with async processing.

### Text-to-Image

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('modelslab', 'flux')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

$imageUrl = $response->images[0]->url;
```

### With Provider Options

```php
$response = Prism::image()
    ->using('modelslab', 'flux')
    ->withPrompt('A futuristic city skyline')
    ->withProviderOptions([
        'negative_prompt' => 'blurry, low quality',
        'width' => 1024,
        'height' => 1024,
        'samples' => 1,
        'seed' => 12345,
        'guidance_scale' => 7.5,
        'num_inference_steps' => 30,
        'scheduler' => 'UniPCMultistepScheduler',
        'safety_checker' => false,
    ])
    ->generate();
```

### Image-to-Image

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Image;

$sourceImage = Image::fromUrl('https://example.com/image.jpg');

$response = Prism::image()
    ->using('modelslab', 'flux')
    ->withPrompt('Transform this into an oil painting', [$sourceImage])
    ->withProviderOptions([
            'scheduler' => 'UniPCMultistepScheduler',
    ])
    ->generate();
```

## Text-to-Speech

Generate audio from text with customizable voices and languages.

### Basic Usage

```php
use Prism\Prism\Facades\Prism;

$response = Prism::audio()
    ->using('modelslab', 'tts')
    ->withInput('Hello, welcome to ModelsLab!')
    ->withVoice('henry')
    ->asAudio();

// Save the audio file
$audioContent = base64_decode($response->audio->base64);
file_put_contents('output.mp3', $audioContent);
```

### With Language and Speed Options

```php
  $response = Prism::audio()
    ->using('modelslab', 'tts')
    ->withInput('Bonjour, bienvenue!')
    ->withVoice('elodie')
    ->withProviderOptions([
        'language' => 'french',
        'speed' => 1.2,
        'emotion' => true,
    ])
    ->asAudio();
```

### Provider Options for Text-to-Speech

| Option | Description |
|--------|-------------|
| `language` | Language for speech (default: `american english`) |
| `speed` | Speech speed multiplier |
| `emotion` | Emotional tone of the speech, true or false |
| `webhook` | Webhook URL for async notifications |
| `track_id` | Custom tracking ID |

## Speech-to-Text

Transcribe audio files to text.

### Basic Usage

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;

$audioFile = Audio::fromPath('/path/to/recording.mp3');

$response = Prism::audio()
    ->using('modelslab', 'stt')
    ->withInput($audioFile)
    ->asText();

echo $response->text;
```

### From URL

```php
$audioFile = Audio::fromUrl('https://example.com/audio.mp3');

$response = Prism::audio()
    ->using('modelslab', 'stt')
    ->withInput($audioFile)
    ->asText();
```

### With Options

```php
$response = Prism::audio()
    ->using('modelslab', 'stt')
    ->withInput($audioFile)
    ->withProviderOptions([
        'language' => 'en',
        'timestamp_level' => 'word',
    ])
    ->asText();
```

### Provider Options for Speech-to-Text

| Option | Description |
|--------|-------------|
| `language` | Language code for transcription |
| `timestamp_level` | Timestamp granularity (`word`, `sentence`) |
| `webhook` | Webhook URL for async notifications |
| `track_id` | Custom tracking ID |

## Async Processing

ModelsLab uses async processing for image generation and audio operations. The provider automatically handles polling for results, so you don't need to manage this manually. Results are returned once processing is complete.

## Text/LLM Generation

ModelsLab provides access to both open-source and closed-source language models through their unified API.

### Basic Usage

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('modelslab', 'gpt-5-mini')
    ->withPrompt('Explain quantum computing in simple terms')
    ->asText();
```

### With System Prompt

```php
$response = Prism::text()
    ->using('modelslab', 'gpt-5-mini')
    ->withSystemPrompt('You are a helpful coding assistant.')
    ->withPrompt('Write a function to reverse a string in PHP')
    ->asText();
```

### Model Parameters

```php
$response = Prism::text()
    ->using('modelslab', 'claude-3.5-sonnet')
    ->withTemperature(0.7)
    ->withTopP(0.9)
    ->withMaxTokens(1000)
    ->withPrompt('Write a creative story')
    ->asText();
```

## Streaming

ModelsLab supports streaming responses for text generation, allowing you to process tokens as they arrive.

### Basic Streaming

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;

$stream = Prism::text()
    ->using('modelslab', 'gpt-5-mini')
    ->withPrompt('Write a short story')
    ->asStream();

foreach ($stream as $event) {
    if ($event instanceof TextDeltaEvent) {
        echo $event->delta;
    }
}
```

### Server-Sent Events

For real-time web applications:

```php
return Prism::text()
    ->using('modelslab', 'llama-3.3-70b')
    ->withPrompt(request('message'))
    ->asEventStreamResponse();
```

## Error Handling

```php
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

try {
    $response = Prism::text()
        ->using('modelslab', 'gpt-4o')
        ->withPrompt('Hello')
        ->asText();
} catch (PrismRateLimitedException $e) {
    // Handle rate limiting
} catch (PrismException $e) {
    // Handle other errors
    echo $e->getMessage();
}
```
