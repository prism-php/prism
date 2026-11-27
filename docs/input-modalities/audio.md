# Audio

Prism supports including audio files in your messages for advanced analysis with supported providers like Gemini.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Check that both the provider integration and selected model support the input type. This page covers audio inside a text request; use [audio processing](/core-concepts/audio) for transcription and speech synthesis.

## Provider input forms

| Integration | Audio input |
|---|---|
| Gemini | Local/raw/base64 bytes, or a recognized Gemini File API URI; arbitrary URLs need an explicit fetch |
| OpenRouter | Local/raw/base64 bytes mapped to `input_audio`; the routed model must support audio |
| Requesty | Local/raw/base64 bytes mapped to `input_audio`; the routed model must support audio |

OpenRouter and Requesty map WAV, MP3, OGG and WebM MIME types to format labels; unrecognized MIME types fall back to `wav`. Supply correctly encoded audio and an accurate MIME type. An unfetched URL is refused by their audio mapper.

::: tip
For other input modalities like videos and images, see their respective documentation pages:
- [Video documentation](/input-modalities/video.html)
- [Images documentation](/input-modalities/images.html)
:::

## Getting started

To add an audio file to your prompt, use the `withPrompt` method with an `Audio` value object:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Audio;

// From a local path
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        "What's in this audio?",
        [Audio::fromLocalPath(path: '/path/to/audio.mp3')]
    )
    ->asText();

// From a path on a storage disk
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        "What's in this audio?",
        [Audio::fromStoragePath(
            path: '/path/to/audio.mp3',
            diskName: 'my-disk' // optional - omit/null for default disk
        )]
    )
    ->asText();

// From a URL you trust — fetched explicitly before sending
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Analyze this audio:',
        [Audio::fromUrl(url: 'https://example.com/audio.mp3')->fetchUrlContent()]
    )
    ->asText();

// From base64
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Analyze this audio:',
        [Audio::fromBase64(
            base64: base64_encode(file_get_contents('/path/to/audio.mp3')),
            mimeType: 'audio/mpeg'
        )]
    )
    ->asText();

// From raw content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Analyze this audio:',
        [Audio::fromRawContent(
            rawContent: file_get_contents('/path/to/audio.mp3'),
            mimeType: 'audio/mpeg'
        )]
    )
    ->asText();
```

## Alternative: Using withMessages

You can also include audio files using the message-based approach:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Audio;

$message = new UserMessage(
    "What's in this audio?",
    [Audio::fromLocalPath(path: '/path/to/audio.mp3')]
);

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withMessages([$message])
    ->asText();
```

## Supported Audio Types

Prism supports a variety of audio formats, including:

- MP3 (audio/mpeg)
- WAV (audio/x-wav, audio/wav)
- AAC (audio/aac)
- FLAC (audio/flac)

The specific supported formats depend on the provider. Gemini is currently the main provider with comprehensive audio analysis capabilities. Check the provider's documentation for a complete list of supported formats.

## Transfer mediums

Providers are not consistent in their support of sending raw contents, base64 and/or URLs.

Prism converts between supported representations where possible. The following conversions and limitations apply.

### Supported conversions
- A URL stays a reference. If your provider needs audio bytes, call `fetchPublicUrlContent()` explicitly for a user- or model-supplied URL. Use the unguarded `fetchUrlContent()` only for URLs your application trusts. See [Fetching remote media](/input-modalities/images#fetching-remote-media) for limits and refusals.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations
- Where a provider only supports URLs: if you provide a file path, raw contents or base64, for security reasons Prism does not create a URL for you and your request will fail.
