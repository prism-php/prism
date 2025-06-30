# Audio

Prism supports including audio files in your messages for advanced analysis with supported providers like Gemini.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Note however that provider support may differ by model. If you receive error messages with a provider that Prism indicates is supported, check the provider's documentation as to whether the model you are using supports audio files.

::: tip
For other input modalities like videos and images, see their respective documentation pages:
- [Video documentation](/input-modalities/video.html)
- [Images documentation](/input-modalities/images.html)
:::

## Getting started

To add an audio file to your message, add an `Audio` value object to the `additionalContent` property:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Audio;

// From a local path
$message = new UserMessage(
    "What's in this audio?",
    [Audio::fromLocalPath(path: '/path/to/audio.mp3')]
);

// From a path on a storage disk
$message = new UserMessage(
    "What's in this audio?",
    [Audio::fromStoragePath(
        path: '/path/to/audio.mp3', 
        disk: 'my-disk' // optional - omit/null for default disk
    )]
);

// From a URL
$message = new UserMessage(
    'Analyze this audio:',
    [Audio::fromUrl(url: 'https://example.com/audio.mp3')]
);

// From base64
$message = new UserMessage(
    'Analyze this audio:',
    [Audio::fromBase64(
        base64: base64_encode(file_get_contents('/path/to/audio.mp3')),
        mimeType: 'audio/mpeg'
    )]
);

// From raw content
$message = new UserMessage(
    'Analyze this audio:',
    [Audio::fromRawContent(
        rawContent: file_get_contents('/path/to/audio.mp3'),
        mimeType: 'audio/mpeg'
    )]
);

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
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

Prism tries to smooth over these rough edges, but its not always possible.

### Supported conversions
- Where a provider does not support URLs: Prism will fetch the URL and use base64 or rawContent.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations
- Where a provider only supports URLs: if you provide a file path, raw contents or base64, for security reasons Prism does not create a URL for you and your request will fail.
