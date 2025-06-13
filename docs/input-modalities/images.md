# Images

Prism supports including images in your messages for vision analysis for most providers.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Note however that provider support may differ by model. If you receive error messages with a provider that Prism indicates is supported, check the provider's documentation as to whether the model you are using supports images.

## Getting started

To add an image to your message, add an `Image` value object to the `additionalContent` property:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Image;

// From a local path
$message = new UserMessage(
    "What's in this image?",
    [Image::fromLocalPath(path: '/path/to/image.jpg')]
);

// From a path on a storage disk
$message = new UserMessage(
    "What's in this image?",
    [Image::fromStoragePath(
        path: '/path/to/image.jpg', 
        disk: 'my-disk' // optional - omit/null for default disk
    )]
);

// From a URL
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromUrl(url: 'https://example.com/diagram.png')]
);

// From base64
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromBase64(base64: base64_encode(file_get_contents('/path/to/image.jpg')))]
);

// From raw content
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromRawContent(rawContent: file_get_contents('/path/to/image.jpg'))]
);

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([$message])
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