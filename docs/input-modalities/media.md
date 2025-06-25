# Media

Prism supports including various media types (videos, audio files, and YouTube videos) in your messages for advanced analysis with supported providers like Gemini.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Note however that provider support may differ by model. If you receive error messages with a provider that Prism indicates is supported, check the provider's documentation as to whether the model you are using supports media files.

## Getting started

To add media to your message, add a `Media` value object to the `additionalContent` property:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Media;

// From a local path (video or audio)
$message = new UserMessage(
    "What's in this video?",
    [Media::fromLocalPath(path: '/path/to/video.mp4')]
);

// From a path on a storage disk
$message = new UserMessage(
    "What's in this video?",
    [Media::fromStoragePath(
        path: '/path/to/video.mp4', 
        disk: 'my-disk' // optional - omit/null for default disk
    )]
);

// From a URL
$message = new UserMessage(
    'Analyze this video:',
    [Media::fromUrl(url: 'https://example.com/video.mp4')]
);

// From a YouTube URL (automatically extracts the video ID)
$message = new UserMessage(
    'What is this YouTube video about?',
    [Media::fromUrl(url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
);

// From shortened YouTube URL
$message = new UserMessage(
    'What is this YouTube video about?',
    [Media::fromUrl(url: 'https://youtu.be/dQw4w9WgXcQ')]
);

// From base64
$message = new UserMessage(
    'Analyze this audio:',
    [Media::fromBase64(
        base64: base64_encode(file_get_contents('/path/to/audio.mp3')),
        mimeType: 'audio/mpeg'
    )]
);

// From raw content
$message = new UserMessage(
    'Analyze this video:',
    [Media::fromRawContent(
        rawContent: file_get_contents('/path/to/video.mp4'),
        mimeType: 'video/mp4'
    )]
);

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([$message])
    ->asText();
```

## Supported Media Types

Prism supports a variety of media types, including:

- **Video Files**: Most common video formats (MP4, MOV, etc.)
- **Audio Files**: Common audio formats (MP3, WAV, etc.)
- **YouTube Videos**: Simply pass a YouTube URL and Prism automatically extracts the video ID

The specific supported formats depend on the provider. Check the provider's documentation for a complete list of supported formats.

## YouTube Video Support

Prism provides seamless support for YouTube videos. When you pass a YouTube URL to `Media::fromUrl()`, Prism automatically extracts the video ID and sends it to the provider in the appropriate format.

Supported YouTube URL formats:

- Standard: `https://www.youtube.com/watch?v=VIDEO_ID`
- Shortened: `https://youtu.be/VIDEO_ID`

Example:

```php
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-1.5-flash')
    ->withMessages([
        new UserMessage(
            'What is this YouTube video about?',
            additionalContent: [
                Media::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'),
            ],
        ),
    ])
    ->asText();
```

## Transfer mediums 

Providers are not consistent in their support of sending raw contents, base64 and/or URLs (as noted above). 

Prism tries to smooth over these rough edges, but its not always possible.

### Supported conversions
- Where a provider does not support URLs: Prism will fetch the URL and use base64 or rawContent.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations
- Where a provider only supports URLs: if you provide a file path, raw contents or base64, for security reasons Prism does not create a URL for you and your request will fail.
