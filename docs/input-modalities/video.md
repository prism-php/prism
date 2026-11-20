# Video

Prism supports including video files and YouTube videos in your messages for advanced analysis with supported providers like Gemini.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Check that both the provider integration and selected model support the input type.

## Provider input forms

| Integration | Video input |
|---|---|
| Gemini | Inline bytes or recognized YouTube/Gemini File API URL references; other URLs need an explicit fetch |
| OpenRouter | URL reference or bytes encoded as a data URL in `video_url` |
| Requesty | URL reference or bytes encoded as a data URL in `video_url` |

Routed model support, MIME types and size limits vary. A mapper accepting the media does not establish that the selected model can analyze it. Use the provider guide and your model's input requirements.

::: tip
For other input modalities like audio and images, see their respective documentation pages:
- [Audio documentation](/input-modalities/audio.html)
- [Images documentation](/input-modalities/images.html)
:::

## Getting started

To add a video to your prompt, use the `withPrompt` method with a `Video` value object:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Video;

// From a local path
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        "What's in this video?",
        [Video::fromLocalPath(path: '/path/to/video.mp4')]
    )
    ->asText();

// From a path on a storage disk
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        "What's in this video?",
        [Video::fromStoragePath(
            path: '/path/to/video.mp4',
            diskName: 'my-disk' // optional - omit/null for default disk
        )]
    )
    ->asText();

// From a URL
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Analyze this video:',
        [Video::fromUrl(url: 'https://example.com/video.mp4')->fetchUrlContent()] // Gemini fetches only YouTube and File API URLs itself
    )
    ->asText();

// From a YouTube URL (passed to Gemini as a full URL reference)
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'What is this YouTube video about?',
        [Video::fromUrl(url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
    )
    ->asText();

// From shortened YouTube URL
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'What is this YouTube video about?',
        [Video::fromUrl(url: 'https://youtu.be/dQw4w9WgXcQ')]
    )
    ->asText();

// From base64
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Analyze this video:',
        [Video::fromBase64(
            base64: base64_encode(file_get_contents('/path/to/video.mp4')),
            mimeType: 'video/mp4'
        )]
    )
    ->asText();

// From raw content
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'Analyze this video:',
        [Video::fromRawContent(
            rawContent: file_get_contents('/path/to/video.mp4'),
            mimeType: 'video/mp4'
        )]
    )
    ->asText();
```

## Alternative: Using withMessages

You can also include videos using the message-based approach:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Video;

$message = new UserMessage(
    "What's in this video?",
    [Video::fromLocalPath(path: '/path/to/video.mp4')]
);

$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withMessages([$message])
    ->asText();
```

## Supported Video Types

Prism supports a variety of video formats, including:

- MP4 (video/mp4)
- MOV (video/quicktime)
- WEBM (video/webm)
- AVI (video/x-msvideo)
- YouTube videos (via URL)

The specific supported formats depend on the provider. Gemini is currently the main provider with comprehensive video analysis capabilities. Check the provider's documentation for a complete list of supported formats.

## YouTube Video Support

Pass a supported YouTube URL to `Video::fromUrl()`. Gemini receives the full URL as a `file_uri`; Prism does not extract a video ID or download the video.

Supported YouTube URL formats:

- Standard: `https://www.youtube.com/watch?v=VIDEO_ID`
- Shortened: `https://youtu.be/VIDEO_ID`

The recognizer matches HTTP(S) prefixes for `youtube.com/watch` and `youtu.be/`, with optional `www.` and case-insensitive matching. It does not extract or validate the video ID. Mobile-host, `/shorts/`, `/embed/` and `youtube-nocookie.com` forms are not recognized as YouTube references. Normalize those URLs in your application or use a supported form; recognition is not a URL-safety check.

Example:

```php
$response = Prism::text()
    ->using(Provider::Gemini, 'gemini-2.5-flash')
    ->withPrompt(
        'What is this YouTube video about?',
        [Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ')]
    )
    ->asText();
```

## Transfer mediums

Providers are not consistent in their support of sending raw contents, base64 and/or URLs.

Prism converts between supported representations where possible. The following conversions and limitations apply.

### Supported conversions
- A URL stays a reference. If your provider needs video bytes, call `fetchPublicUrlContent()` explicitly for a user- or model-supplied URL. Use the unguarded `fetchUrlContent()` only for URLs your application trusts. See [Fetching remote media](/input-modalities/images#fetching-remote-media) for limits and refusals.
- Where you provide a file, base64 or rawContent: Prism will switch between base64 and rawContent depending on what the provider accepts.

### Limitations
- Where a provider only supports URLs: if you provide a file path, raw contents or base64, for security reasons Prism does not create a URL for you and your request will fail.
