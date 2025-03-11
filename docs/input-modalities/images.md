# Images

Prism supports including images in your messages for vision analysis for most providers.

See the [provider support table](/getting-started/introduction.html#provider-support) to check whether Prism supports your chosen provider.

Note however that not all models with a supported provider support vision. If you are running into issues with not supported messages, double check the provider model documentation for support.

## Getting started

To add an image to your message, add an `Image` value object to the `additionalContent` property:

```php
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\Support\Image;

// From a local file
$message = new UserMessage(
    "What's in this image?",
    [Image::fromPath('/path/to/image.jpg')]
);

// From a URL
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromUrl('https://example.com/diagram.png')]
);

// From a URL which does not end in the image format,
// you can pass the mime type as the second argument
// e.g. if you are generating a temporary URL
$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromUrl(
        'https://storage.example.com/diagram.png?AccessID=test&Expires=1742330260&Signature=dVQaFcIk9FJWIVnvV1%2FWu',
        'image/png'
    )]
);

// From a Base64
$image = base64_encode(file_get_contents('/path/to/image.jpg'));

$message = new UserMessage(
    'Analyze this diagram:',
    [Image::fromBase64($image)]
);

$response = Prism::text()
    ->using(Provider::Anthropic, 'claude-3-5-sonnet-20241022')
    ->withMessages([$message])
    ->generate();
```
