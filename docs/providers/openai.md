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

### Metadata

```php
$response = Prism::structured()
    ->withProviderOptions([ // [!code focus]
        'meta' => [ // [!code focus]
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

### Caching

Automatic caching does not currently work with JsonMode. Please ensure you use StructuredMode if you wish to utilise automatic caching.

## Provider Tools

OpenAI offers built-in provider tools that can be used alongside your custom tools. These tools are executed by OpenAI's infrastructure and provide specialized capabilities. For more information about the difference between custom tools and provider tools, see [Tools & Function Calling](/core-concepts/tools-function-calling#provider-tools).

### Code Interpreter

The OpenAI code interpreter allows your AI to execute Python code in a secure, sandboxed environment. This is particularly useful for mathematical calculations, data analysis, and code execution tasks.

```php
use Prism\Prism\Prism;
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

GPT-Image-1 supports sophisticated image editing operations:

```php
// Load your source image and mask
$originalImage = base64_encode(file_get_contents('/path/to/photo.jpg'));
$maskImage = base64_encode(file_get_contents('/path/to/mask.png'));

$response = Prism::image()
    ->using('openai', 'gpt-image-1')
    ->withPrompt('Replace the sky with a dramatic sunset')
    ->withProviderOptions([
        'image' => $originalImage,          // Base64 encoded original image
        'mask' => $maskImage,               // Base64 encoded mask (optional)
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
use Prism\Prism\Prism;

$response = Prism::audio()
    ->using('openai', 'gpt-4o-mini-tts')
    ->withInput('Hello, welcome to our application!')
    ->withVoice('alloy')
    ->asAudio();

// Save the audio file
$audioData = base64_decode($response->audio->base64);
file_put_contents('welcome.mp3', $audioData);
```

\
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
